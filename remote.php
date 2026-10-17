<?php
/**
 * FamTask – Externe Musikquellen (remote.php)
 *
 * Verwalten Fernspeicher (WebDAV, FTP/FTPS, HTTPS/S3, gemountete Laufwerke)
 * und importieren daraus Musik in my/audio/<Album>/.
 *
 * GRUNDENTSCHEIDUNG: Import statt Proxy.
 * Dateien werden einmal in audio/ kopiert (mode=copy) oder dorthin verlinkt
 * (mode=link). Dadurch funktionieren Streamen, Cover, Favoriten und die
 * Oberflaeche unveraendert weiter – und ohne Zugangsdaten pro Wiedergabe.
 *
 * WICHTIG – SMB/CIFS und NFS:
 * PHP kann Netzwerkdateisysteme NICHT selbst mounten. Fuer diese Freigaben
 * muss der Host mounten (fstab bzw. Docker-Bind-Mount), und FamTask sieht die
 * Dateien dann als ganz normalen Ordner. Siehe docs/REMOTE_SOURCES_DE.md.
 *
 * SICHERHEIT:
 *  - Zugangsdaten liegen ausschliesslich in der Datenbank und werden nie
 *    ueber diese Schnittstelle zurueckgegeben (nur masked=true).
 *  - Achtung: FamTask hat bislang KEINE echte Benutzerverwaltung; der
 *    Familiencode ist keine Authentifizierung. Diese Datei darf deshalb nicht
 *    ungeschuetzt ins Internet exposiert werden.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', getenv('FT_DEBUG') ? '1' : '0');
ini_set('log_errors', '1');
if (!ini_get('date.timezone')) { @date_default_timezone_set('UTC'); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const FAMTASK = true;

// ── Grenzwerte ────────────────────────────────────────────────────────────
const MAX_FILES      = 5000;      // Dateien pro Import-Vorgang
const MAX_DEPTH      = 4;         // Unterordner-Ebenen bei rekursivem Import
const MAX_FILE_BYTES = 1073741824; // 1 GB pro Datei
const MAX_TOTAL      = 2147483648; // 2 GB pro Import-Vorgang
const CURL_TIMEOUT   = 20;

const AUDIO_EXTS = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'opus'];

// ═══════════════════════════════════════════════════════════════════════════
//  Ausgabe / Eingabe
// ═══════════════════════════════════════════════════════════════════════════
function jsonOut(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    jsonOut(['ok' => false, 'error' => $msg]);
}

/** Familiencode wie in api.php – ist KEINE Authentifizierung. */
function getFamilyCode(): string {
    $code = trim($_REQUEST['fc'] ?? $_SERVER['HTTP_X_FAMILY_CODE'] ?? '');
    $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    return ($code === '' || $code === 'DEFAULT') ? 'FAM001' : $code;
}

/** Namen aus der Anfrage – tolerant, um Kodierungsprobleme zu vermeiden. */
function inStr(string $key, string $default = ''): string {
    $v = $_REQUEST[$key] ?? $default;
    if (is_array($v)) return $default;
    $v = trim((string)$v);
    // defekte UTF-8-Sequenzen abschneiden, damit json_encode nicht scheitert
    if (!mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    }
    return $v;
}

/**
 * PHP fuellt $_REQUEST bei application/json nicht – daher den Body selbst
 * lesen und in $_REQUEST spiegeln. GET-Parameter bleiben unangetastet.
 */
function readInput(): void {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === false) return;
    $raw = file_get_contents('php://input');
    if (!$raw || strlen($raw) > 262144) return;      // 256 KB Deckel
    $data = json_decode($raw, true);
    if (!is_array($data)) return;
    foreach ($data as $k => $v) {
        if (is_scalar($v)) $_REQUEST[$k] = $v;
    }
}
readInput();

// ═══════════════════════════════════════════════════════════════════════════
//  Datenbank
// ═══════════════════════════════════════════════════════════════════════════
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $cfgFile = __DIR__ . '/.famtask_cfg.php';
    if (!is_file($cfgFile)) fail('FamTask ist nicht konfiguriert (CFG fehlt).', 500);
    $cfg = require $cfgFile;
    if (!is_array($cfg) || empty($cfg['host'])) fail('Konfiguration unlesbar.', 500);

    try {
        $pdo = new PDO(
            "mysql:host={$cfg['host']};port=" . ($cfg['port'] ?? 3306) . ";dbname={$cfg['db']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    } catch (PDOException $e) {
        fail('Datenbank nicht erreichbar.', 500);
    }
    return $pdo;
}

function ensureSchema(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS famtask_remotes (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        family_code VARCHAR(10)  NOT NULL DEFAULT 'FAM001',
        name        VARCHAR(80)  NOT NULL,
        kind        ENUM('webdav','ftp','https','local') NOT NULL,
        url         VARCHAR(500) NOT NULL,
        username    VARCHAR(200) NOT NULL DEFAULT '',
        password    VARCHAR(500) NOT NULL DEFAULT '',
        mode        ENUM('copy','link') NOT NULL DEFAULT 'copy',
        last_synced TIMESTAMP    NULL DEFAULT NULL,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_remote (family_code, name),
        INDEX idx_remote_fam (family_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Holt eine Quelle; password wird nur intern gebraucht. */
function loadSource(int $id, string $fc): ?array {
    $st = db()->prepare("SELECT * FROM famtask_remotes WHERE id=? AND family_code=?");
    $st->execute([$id, $fc]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Ohne Passwort, Passwort nur als Hinweis ob gesetzt. */
function publicSource(array $row): array {
    return [
        'id'         => (int)$row['id'],
        'name'       => $row['name'],
        'kind'       => $row['kind'],
        'url'        => $row['url'],
        'username'   => $row['username'],
        'mode'       => $row['mode'],
        'hasPassword'=> $row['password'] !== '',
        'lastSynced' => $row['last_synced'],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Pfad- und Namenssicherung
// ═══════════════════════════════════════════════════════════════════════════

/** Entfernt alles, was in einem Dateinamen gefaehrlich oder unbrauchbar ist. */
function safeName(string $raw, string $fallback = 'Album'): string {
    $n = str_replace(["\\", '/'], '-', $raw);
    $n = preg_replace('/[\x00-\x1F\x7F]/u', '', $n) ?? '';
    $n = preg_replace('/[<>:"|?*]/u', '', $n) ?? '';
    $n = preg_replace('/\.\.+/', '.', $n) ?? '';
    $n = trim($n, " \t.-");
    if ($n === '') return $fallback;
    if (mb_strlen($n) > 80) $n = rtrim(mb_substr($n, 0, 80));
    return $n;
}

/** Erlaubte Stammverzeichnisse fuer 'local'-Quellen (SMB/NFS-Bind-Mounts). */
function allowedLocalRoots(): array {
    $raw = getenv('FAMTASK_REMOTE_ROOTS') ?: '/mnt,/media,/srv,/net,/Volumes';
    $out = [];
    foreach (explode(',', $raw) as $r) {
        $r = rtrim(trim($r), '/');
        if ($r !== '') $out[] = $r;
    }
    return $out;
}

/**
 * Prueft einen lokalen Pfad: muss absolut, existierend und unterhalb eines
 * erlaubten Stammes sein. Verhindert, dass jemand /etc oder / ins Web haengt.
 */
function validateLocalPath(string $path): string {
    $real = realpath($path);
    if ($real === false || !is_dir($real)) fail('Pfad nicht gefunden oder kein Ordner: ' . $path);
    $real = rtrim(str_replace('\\', '/', $real), '/');

    $ok = false;
    foreach (allowedLocalRoots() as $root) {
        $r = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
        if ($real === $r || str_starts_with($real . '/', $r . '/')) { $ok = true; break; }
    }
    if (!$ok) {
        fail('Pfad nicht freigegeben. Erlaubt sind: ' . implode(', ', allowedLocalRoots())
             . ' (setzbar mit FAMTASK_REMOTE_ROOTS).', 403);
    }
    return $real;
}

/** Zielordner fuer den Import – muss immer unterhalb von my/audio liegen. */
function targetAlbumDir(string $album): string {
    $root = realpath(__DIR__ . '/audio');
    if ($root === false) fail('audio/-Ordner fehlt.', 500);
    $dir = $root . '/' . safeName($album);
    $realParent = realpath(dirname($dir));
    if ($realParent !== $root) fail('Ungueltiges Zielverzeichnis.', 400);
    return $dir;
}

function isAudioFile(string $name): bool {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, AUDIO_EXTS, true);
}

function humanSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

// ═══════════════════════════════════════════════════════════════════════════
//  URL-Pruefung (SSRF-Bremse)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Blockt die klassischen SSRF-Ziele. Private Adressen bleiben erlaubt – ein NAS
 * steht im Heimnetz fast immer auf 192.168.x – aber Link-Local-Metadaten des
 * Cloud-Anbieters und der Host selbst nicht.
 */
function assertUrlAllowed(string $url): void {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) fail('Ungueltige URL.', 400);
    if (!empty($p['user']) || !empty($p['pass'])) {
        fail('Zugangsdaten bitte in die Felder Benutzer/Passwort, nicht in die URL.', 400);
    }
    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https', 'webdav', 'webdavs', 'ftp', 'ftps', 's3'], true)) {
        fail('Unbekanntes Schema: ' . $scheme, 400);
    }
    $host = strtolower($p['host']);
    if (in_array($host, ['169.254.169.254', 'metadata.google.internal', 'metadata.goog'], true)) {
        fail('Cloud-Metadaten-Endpunkt ist gesperrt.', 403);
    }
}

function kindForUrl(string $url): string {
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
    return match ($scheme) {
        'webdav', 'webdavs' => 'webdav',
        'ftp',    'ftps'    => 'ftp',
        's3'                => 'https',
        default             => 'https',
    };
}

// ═══════════════════════════════════════════════════════════════════════════
//  Adapter: Ordnerinhalt auflisten
//  Einheitliches Format: [['name'=>..,'isDir'=>bool,'size'=>int,'href'=>..], …]
// ═══════════════════════════════════════════════════════════════════════════

/** WebDAV via PROPFIND – funktioniert ueberall wo curl laeuft. */
function webdavList(string $url, string $user, string $pass): array {
    if (!function_exists('curl_init')) fail('PHP-Extension curl fehlt.', 500);

    $url  = rtrim($url, '/') . '/';
    $body = '<?xml version="1.0" encoding="utf-8"?>'
          . '<d:propfind xmlns:d="DAV:"><d:prop>'
          . '<d:resourcetype/><d:getcontentlength/><d:getlastmodified/>'
          . '</d:prop></d:propfind>';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'PROPFIND',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERPWD        => $user . ($pass !== '' ? ':' . $pass : ''),
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Depth: 1', 'Content-Type: application/xml; charset=utf-8'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
    ]);
    $xml = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($xml === false) fail('WebDAV nicht erreichbar (' . $url . '): ' . $err, 502);
    if ($code === 401 || $code === 403) fail('WebDAV: Benutzer oder Passwort falsch.', 401);
    if ($code < 200 || $code >= 300) fail('WebDAV antwortete mit HTTP ' . $code . ' (' . $url . ')', 502);

    return parseWebdavMultistatus((string)$xml, $url);
}

/** Zaehlt <d:response>-Bloecke ohne XML-Extension (die ist nicht ueberall da). */
function parseWebdavMultistatus(string $xml, string $base): array {
    $out = [];
    if (!preg_match_all('#<[^>]*:?response[^>]*>(.*?)</[^>]*:?response>#si', $xml, $m)) return $out;

    // href ist nur ein Pfad, $base eine volle URL – beide auf denselben
    // Pfad normalisieren, sonst wird der Ordner als sein eigener Unterordner
    // gelistet und der Import laeuft in eine Endlosschleife.
    $norm = static function (string $p): string {
        $p = preg_replace('#/+#', '/', $p);
        return rtrim('/' . ltrim((string)$p, '/'), '/') . '/';
    };
    $reqPath = $norm((string)(parse_url($base, PHP_URL_PATH) ?? '/'));

    foreach ($m[1] as $idx => $block) {
        if (!preg_match('#<[^>]*:?href[^>]*>(.*?)</[^>]*:?href>#si', $block, $h)) continue;
        $href = trim(html_entity_decode(urldecode(trim($h[1])), ENT_QUOTES, 'UTF-8'));

        $isDir = (bool)preg_match('#<[^>]*:?collection\s*/?>#i', $block);
        $size  = 0;
        if (preg_match('#<[^>]*:?getcontentlength[^>]*>\s*(\d+)\s*</#si', $block, $s)) {
            $size = (int)$s[1];
        }
        $mtime = 0;
        if (preg_match('#<[^>]*:?getlastmodified[^>]*>\s*([^<]+?)\s*</#si', $block, $lm)) {
            $ts = strtotime(trim($lm[1]));
            if ($ts) $mtime = $ts;
        }

        // Das angefragte Verzeichnis selbst ueberspringen
        if ($norm($href) === $reqPath) continue;

        $name = basename(rtrim($href, '/'));
        if ($name === '' || $name === '.' || $name === '..') continue;

        $out[] = ['name' => $name, 'isDir' => $isDir, 'size' => $size, 'mtime' => $mtime, 'href' => $href];
    }
    return $out;
}

/** FTP/FTPS ueber die Stream-Wrapper der ftp-Extension. */
function ftpList(string $url, string $user, string $pass): array {
    if (!in_array('ftp', stream_get_wrappers(), true)) {
        fail('PHP-Extension ftp fehlt (im Dockerfile aktivieren: ftp).', 500);
    }
    $target = rtrim($url, '/') . '/';
    if ($user !== '') {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'ftp';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = $parts['path'] ?? '/';
        $target = $scheme . '://' . rawurlencode($user) . ':' . rawurlencode($pass)
                . '@' . $host . $port . $path;
        $target = rtrim($target, '/') . '/';
    }
    $ctx = stream_context_create(['ftp' => ['timeout' => CURL_TIMEOUT]]);
    $dh  = @opendir($target, $ctx);
    if ($dh === false) fail('FTP nicht erreichbar oder Zugang verweigert.', 502);

    $out = [];
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $isDir = @is_dir($target . $e);
        $size  = $isDir ? 0 : @filesize($target . $e);
        $out[] = ['name' => $e, 'isDir' => $isDir, 'size' => $isDir ? 0 : (int)$size, 'mtime' => 0, 'href' => $target . $e];
    }
    closedir($dh);
    return $out;
}

/** Lokaler Ordner (SMB/NFS-Bind-Mount). */
function localList(string $path): array {
    $real = validateLocalPath($path);
    $dh = @opendir($real);
    if ($dh === false) fail('Ordner nicht lesbar.', 403);

    $out = [];
    while (($e = readdir($dh)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $full = $real . '/' . $e;
        $isDir = is_dir($full);
        $out[] = [
            'name'  => $e,
            'isDir' => $isDir,
            'size'  => $isDir ? 0 : (int)@filesize($full),
            'mtime' => (int)@filemtime($full),
            'href'  => $full,
        ];
    }
    closedir($dh);
    return $out;
}

/** HTTPS/S3: nur Einzeldatei (mp3/…/zip) oder ZIP-Archiv. */
function httpHead(string $url): array {
    if (!function_exists('curl_init')) fail('PHP-Extension curl fehlt.', 500);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size  = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $mtime = (int)curl_getinfo($ch, CURLINFO_FILETIME);
    curl_close($ch);
    if ($code < 200 || $code >= 300) fail('HTTP ' . $code . ' beim Abruf der URL.', 502);
    return ['size' => $size, 'mtime' => $mtime];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Verzeichnis rekursiv einsammeln (Import)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * @return array{0: array<int,array{path:string,size:int,name:string,rel:string}>, 1: int}
 */
function collect(string $dirUrl, string $kind, int $depth = 0, string $rel = '', int &$count = 0): array {
    $files = [];
    if ($depth > MAX_DEPTH || $count >= MAX_FILES) return [$files, $count];

    $entries = ($kind === 'webdav')
        ? webdavList($dirUrl, $GLOBALS['_rm_user'], $GLOBALS['_rm_pass'])
        : ftpList($dirUrl, $GLOBALS['_rm_user'], $GLOBALS['_rm_pass']);

    foreach ($entries as $e) {
        if ($count >= MAX_FILES) break;

        if ($e['isDir']) {
            if ($e['name'] === '.covers' || $e['name'] === '@eaDir') continue;
            [$sub, $count] = collect(
                rtrim($dirUrl, '/') . '/' . rawurlencode($e['name']),
                $kind,
                $depth + 1,
                $rel === '' ? $e['name'] : $rel . '/' . $e['name'],
                $count
            );
            $files = array_merge($files, $sub);
        } elseif (isAudioFile($e['name'])) {
            $files[] = [
                'path' => rtrim($dirUrl, '/') . '/' . rawurlencode($e['name']),
                'name' => $e['name'],
                'rel'  => $rel === '' ? $e['name'] : $rel . '/' . $e['name'],
                'size' => $e['size'],
            ];
            $count++;
        }
    }
    return [$files, $count];
}

/** Lokale Variante von collect(). */
function collectLocal(string $dir, int $depth = 0, string $rel = '', int &$count = 0): array {
    $files = [];
    if ($depth > MAX_DEPTH || $count >= MAX_FILES) return [$files, $count];

    $entries = localList($dir);
    foreach ($entries as $e) {
        if ($count >= MAX_FILES) break;
        if ($e['isDir']) {
            if ($e['name'] === '.covers' || $e['name'] === '@eaDir') continue;
            [$sub, $count] = collectLocal($e['href'], $depth + 1,
                $rel === '' ? $e['name'] : $rel . '/' . $e['name'], $count);
            $files = array_merge($files, $sub);
        } elseif (isAudioFile($e['name'])) {
            $files[] = ['path' => $e['href'], 'name' => $e['name'],
                        'rel' => $rel === '' ? $e['name'] : $rel . '/' . $e['name'], 'size' => $e['size']];
            $count++;
        }
    }
    return [$files, $count];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Admin-Authentifizierung
//  Der Familiencode ist KEINE Authentifizierung. Alle Aktionen, die
//  Zugangsdaten beruehren oder Netzwerkzugriffe ausloesen, brauchen eine
//  gueltige Admin-Anmeldung (Session-Cookie aus api.php oder X-Admin-Pin).
// ═══════════════════════════════════════════════════════════════════════════
function adminPinOf(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT meta_value FROM famtask_meta WHERE meta_key='setting_admin_pin'")->fetchColumn();
        if (is_string($v) && $v !== '') return $v;
    } catch (PDOException $e) {}
    return '1234';
}
function adminGuardTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_auth_guard (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        fails INT NOT NULL DEFAULT 0,
        last_ts INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function adminThrottled(PDO $pdo, int $maxFails = 8, int $window = 900): bool {
    try {
        adminGuardTable($pdo);
        $st = $pdo->prepare("SELECT fails,last_ts FROM famtask_auth_guard WHERE ip=?");
        $st->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
        $r = $st->fetch();
        return $r && (time() - (int)$r['last_ts']) < $window && (int)$r['fails'] >= $maxFails;
    } catch (PDOException $e) {}
    return false;
}
function adminNoteFailure(PDO $pdo, int $window = 900): void {
    try {
        adminGuardTable($pdo);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $st = $pdo->prepare("SELECT fails,last_ts FROM famtask_auth_guard WHERE ip=?");
        $st->execute([$ip]);
        $r = $st->fetch();
        if ($r && (time() - (int)$r['last_ts']) < $window) {
            $pdo->prepare("UPDATE famtask_auth_guard SET fails=fails+1,last_ts=? WHERE ip=?")->execute([time(), $ip]);
        } else {
            $pdo->prepare("INSERT INTO famtask_auth_guard (ip,fails,last_ts) VALUES (?,1,?)
                           ON DUPLICATE KEY UPDATE fails=VALUES(fails),last_ts=VALUES(last_ts)")->execute([$ip, time()]);
        }
    } catch (PDOException $e) {}
}
function adminClearFailures(PDO $pdo): void {
    try {
        adminGuardTable($pdo);
        $pdo->prepare("DELETE FROM famtask_auth_guard WHERE ip=?")->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (PDOException $e) {}
}
function adminAuthOk(PDO $pdo): bool {
    $c = $_COOKIE['ft_admin_auth'] ?? '';
    if (is_string($c) && $c !== '') {
        if (strlen($c) === 64) {
            try {
                $st = $pdo->prepare("SELECT expires_at FROM famtask_admin_sessions WHERE token=?");
                $st->execute([$c]);
                $e = $st->fetchColumn();
                if ($e !== false && (int)$e > time()) return true;
            } catch (PDOException $e) {}
        }
        // Uebergangsweise das alte, deterministische Cookie akzeptieren
        if (strlen($c) === 40 && hash_equals(sha1('ft_admin_' . adminPinOf($pdo)), $c)) return true;
    }
    $pin = $_SERVER['HTTP_X_ADMIN_PIN'] ?? '';
    return is_string($pin) && $pin !== '' && hash_equals(adminPinOf($pdo), $pin);
}
function requireAdminRemote(): void {
    $pdo = db();
    if (adminAuthOk($pdo)) { adminClearFailures($pdo); return; }
    if (adminThrottled($pdo)) fail('Zu viele Fehlversuche. Bitte später erneut versuchen.', 429);
    adminNoteFailure($pdo);
    fail('Admin-Anmeldung erforderlich. Bitte zuerst im Server-Admin-Panel anmelden.', 401);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Aktionen
// ═══════════════════════════════════════════════════════════════════════════

$action = inStr('action', 'status');
$fc     = getFamilyCode();

// ── status: welche Protokolle sind auf diesem Server ueberhaupt moeglich ──
if ($action === 'status') {
    $curl = function_exists('curl_init');
    $ftp  = in_array('ftp', stream_get_wrappers(), true);
    $zip  = class_exists('ZipArchive');
    jsonOut([
        'ok'      => true,
        'can'     => [
            'webdav'  => $curl,
            'ftp'     => $ftp,
            'https'   => $curl,
            'local'   => true,      // gemountete Freigaben
            'link'    => function_exists('symlink'),
            'zip'     => $zip,
        ],
        'roots'   => allowedLocalRoots(),
        'limits'  => [
            'maxFiles' => MAX_FILES,
            'maxTotal' => humanSize(MAX_TOTAL),
            'maxDepth' => MAX_DEPTH,
        ],
        'hint'    => !$ftp
            ? 'FTP/FTPS braucht die PHP-Extension ftp – im Dockerfile ist sie nicht aktiviert.'
            : '',
    ]);
}

// ── list ───────────────────────────────────────────────────────────────────
if ($action === 'list') {
    requireAdminRemote();
    ensureSchema();
    $st = db()->prepare("SELECT * FROM famtask_remotes WHERE family_code=? ORDER BY name");
    $st->execute([$fc]);
    $rows = array_map('publicSource', $st->fetchAll(PDO::FETCH_ASSOC));
    jsonOut(['ok' => true, 'sources' => $rows]);
}

// ── save: Quelle anlegen oder aendern ──────────────────────────────────────
if ($action === 'save') {
    requireAdminRemote();
    ensureSchema();
    $id   = (int)($_REQUEST['id'] ?? 0);
    $name = safeName(inStr('name'), 'Quelle');
    $kind = inStr('kind', 'webdav');
    if (!in_array($kind, ['webdav', 'ftp', 'https', 'local'], true)) fail('Unbekannter Typ.', 400);
    $mode = inStr('mode', 'copy');
    if (!in_array($mode, ['copy', 'link'], true)) fail('Unbekannter Modus.', 400);

    $url = inStr('url');
    if ($kind === 'local') {
        $url = validateLocalPath($url);
        // Verlinken ist bei lokalen Quellen das Sinnvolle
        if ($mode === 'copy' && !inStr('allowLocalCopy')) $mode = 'link';
    } else {
        assertUrlAllowed($url);
        if ($kind === 'https') $url = rtrim($url, '/');
    }

    if ($kind === 'local' && $mode === 'link' && !function_exists('symlink')) {
        fail('symlink() ist auf diesem Server nicht verfuegbar.', 500);
    }

    $user = inStr('username');
    $pass = inStr('password');

    if ($id > 0) {
        $cur = loadSource($id, $fc);
        if (!$cur) fail('Quelle nicht gefunden.', 404);
        // Leeres Passwort = unveraendert lassen
        if ($pass === '') $pass = $cur['password'];
        $st = db()->prepare("UPDATE famtask_remotes
            SET name=?, kind=?, url=?, username=?, password=?, mode=? WHERE id=? AND family_code=?");
        $st->execute([$name, $kind, $url, $user, $pass, $mode, $id, $fc]);
    } else {
        try {
            $st = db()->prepare("INSERT INTO famtask_remotes
                (family_code, name, kind, url, username, password, mode) VALUES (?,?,?,?,?,?,?)");
            $st->execute([$fc, $name, $kind, $url, $user, $pass, $mode]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') fail('Eine Quelle mit diesem Namen existiert bereits.', 409);
            throw $e;
        }
        $id = (int)db()->lastInsertId();
    }
    jsonOut(['ok' => true, 'id' => $id, 'source' => publicSource(loadSource($id, $fc))]);
}

// ── delete ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    requireAdminRemote();
    ensureSchema();
    $id = (int)($_REQUEST['id'] ?? 0);
    $st = db()->prepare("DELETE FROM famtask_remotes WHERE id=? AND family_code=?");
    $st->execute([$id, $fc]);
    jsonOut(['ok' => true, 'deleted' => $st->rowCount()]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  browse / test: Verbindung pruefen und Ordnerinhalt zeigen
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'browse' || $action === 'test') {
    requireAdminRemote();
    ensureSchema();

    if ($action === 'test' && inStr('url') !== '') {
        // Ad-hoc-Test ohne gespeicherte Quelle (Formular testen)
        $kind = inStr('kind', 'webdav');
        $url  = inStr('url');
        $user = inStr('username');
        $pass = inStr('password');
    } else {
        $src = loadSource((int)($_REQUEST['id'] ?? 0), $fc);
        if (!$src) fail('Quelle nicht gefunden.', 404);
        $kind = $src['kind'];
        $url  = $src['url'];
        $user = $src['username'];
        $pass = $src['password'];
    }

    $path = inStr('path');
    if ($path !== '' && $kind !== 'local') {
        $url = rtrim($url, '/') . '/' . ltrim($path, '/');
    } elseif ($path !== '' && $kind === 'local') {
        $url = $path;
    }

    $t0 = microtime(true);
    try {
        if ($kind === 'webdav') {
            $items = webdavList($url, $user, $pass);
        } elseif ($kind === 'ftp') {
            $items = ftpList($url, $user, $pass);
        } elseif ($kind === 'local') {
            $items = localList($url);
        } else {
            $info = httpHead($url);
            $name = basename(parse_url($url, PHP_URL_PATH) ?: 'download');
            $items = [[
                'name'  => $name,
                'isDir' => false,
                'size'  => $info['size'],
                'mtime' => $info['mtime'],
                'href'  => $url,
            ]];
        }
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 502);
    }

    $ms  = (int)round((microtime(true) - $t0) * 1000);
    $out = [];
    foreach ($items as $it) {
        $out[] = [
            'name'  => $it['name'],
            'isDir' => $it['isDir'],
            'size'  => $it['size'],
            'sizeH' => $it['isDir'] ? '' : humanSize($it['size']),
            'mtime' => $it['mtime'],
            'href'  => $it['href'],
        ];
    }
    usort($out, fn($a, $b) => [$b['isDir'], $a['name']] <=> [$a['isDir'], $b['name']]);

    jsonOut([
        'ok'      => true,
        'kind'    => $kind,
        'path'    => $path,
        'count'   => count($out),
        'ms'      => $ms,
        'items'   => $out,
        'audioCnt'=> count(array_filter($out, fn($i) => !$i['isDir'] && isAudioFile($i['name']))),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  import: Musik in my/audio/<Album>/ holen
// ═══════════════════════════════════════════════════════════════════════════
if ($action === 'import') {
    requireAdminRemote();
    ensureSchema();

    $src = loadSource((int)($_REQUEST['id'] ?? 0), $fc);
    if (!$src) fail('Quelle nicht gefunden.', 404);

    $kind   = $src['kind'];
    $user   = $src['username'];
    $pass   = $src['password'];
    $mode   = $src['mode'];
    $album  = safeName(inStr('album', 'Import'), 'Import');
    $path   = inStr('path');
    $url    = $src['url'];
    if ($path !== '' && $kind !== 'local') $url = rtrim($url, '/') . '/' . ltrim($path, '/');
    if ($path !== '' && $kind === 'local') $url = validateLocalPath($path);

    $target = targetAlbumDir($album);
    if (!is_dir($target) && !@mkdir($target, 0775, true)) fail('Zielordner nicht anlegbar: ' . $target, 500);

    $GLOBALS['_rm_user'] = $user;
    $GLOBALS['_rm_pass'] = $pass;
    $t0  = microtime(true);
    $cnt = 0;

    if ($kind === 'local') {
        [$files, $cnt] = collectLocal($url, 0, '', $cnt);
    } elseif ($kind === 'https') {
        // Einzeldatei oder ZIP
        $info = httpHead($url);
        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'track');
        $files = [[ 'path' => $url, 'name' => $name, 'rel' => $name, 'size' => $info['size'] ]];
        $cnt   = 1;
    } else {
        [$files, $cnt] = collect($url, $kind, 0, '', $cnt);
    }

    if (!$files) fail('Keine unterstützten Audiodateien gefunden (mp3, wav, ogg, m4a, flac, aac, opus).');

    $total = 0;
    foreach ($files as $f) $total += (int)$f['size'];
    if ($total > MAX_TOTAL) {
        fail('Import zu gross: ' . humanSize($total) . ' (Limit ' . humanSize(MAX_TOTAL) . ').');
    }

    $done = 0; $unchanged = 0; $skipped = 0; $errors = [];

    foreach ($files as $f) {
        if (strlen($f['name']) > 0 && strpbrk($f['name'], "\\/\0") !== false) {
            $errors[] = 'uebersprungen (unsicherer Name): ' . $f['name'];
            $skipped++;
            continue;
        }
        // Vorhandene Datei gleicher Groesse = unveraendert, also ueberspringen.
// Groesse weicht ab (z.B. neu aufgespielt) -> gezielt ueberschreiben.
// So sammeln sich bei wiederholten Importen keine Dubletten an.
$dest = $target . '/' . $f['name'];
if (is_file($dest) && !is_link($dest)) {
    $have = @filesize($dest);
    if ((int)$f['size'] <= 0 || (int)$have === (int)$f['size']) {
        $unchanged++;      // nicht als 'imported' zaehlen, es wurde nichts uebertragen
        continue;
    }
} elseif (file_exists($dest) || is_link($dest)) {
    // Namenskollision zwischen verschiedenen Remote-Dateien
    $base = pathinfo($f['name'], PATHINFO_FILENAME);
    $ext  = pathinfo($f['name'], PATHINFO_EXTENSION);
    $dest = $target . '/' . $base . '-' . substr(sha1($f['rel']), 0, 6) . '.' . $ext;
}

        if ($mode === 'link' && $kind === 'local') {
            if (@symlink($f['path'], $dest)) { $done++; continue; }
            $errors[] = 'Symlink fehlgeschlagen: ' . $f['name'];
            $skipped++;
            continue;
        }

        $ok = downloadTo($f['path'], $dest, $user, $pass, (string)($f['size'] ?? 0));
        if ($ok) $done++; else { $errors[] = 'Download fehlgeschlagen: ' . $f['name']; $skipped++; }
    }

    db()->prepare("UPDATE famtask_remotes SET last_synced=NOW() WHERE id=? AND family_code=?")
        ->execute([(int)$src['id'], $fc]);

    jsonOut([
        'ok'      => true,
        'album'   => $album,
        'mode'    => $mode,
        'found'   => count($files),
        'imported'=> $done,
        'unchanged'=> $unchanged,
        'skipped' => $skipped,
        'size'    => humanSize($total),
        'ms'      => (int)round((microtime(true) - $t0) * 1000),
        'errors'  => array_slice($errors, 0, 15),
    ]);
}

/** Datei holen – ueber Streams (FTP/WebDAV/HTTP), Groessenlimit beachtet. */
function downloadTo(string $url, string $dest, string $user, string $pass, string $sizeHint): bool {
    $tmp = $dest . '.part';
    $ctx = stream_context_create([
        'http' => ['timeout' => 120, 'follow_location' => 1, 'max_redirects' => 5,
                   'header' => $user !== '' ? 'Authorization: Basic ' . base64_encode($user . ':' . $pass) : ''],
        'ftp'  => ['timeout' => 120],
    ]);
    $stream = @fopen($url, 'rb', false, $ctx);
    if (!$stream) return false;

    $out = @fopen($tmp, 'wb');
    if (!$out) { fclose($stream); return false; }

    $written = 0; $ok = true;
    while (!feof($stream)) {
        $chunk = fread($stream, 262144);
        if ($chunk === false) { $ok = false; break; }
        $written += strlen($chunk);
        if ($written > MAX_FILE_BYTES) { $ok = false; break; }
        if (fwrite($out, $chunk) === false) { $ok = false; break; }
    }
    fclose($stream); fclose($out);

    if (!$ok || $written === 0) { @unlink($tmp); return false; }
    if (!@rename($tmp, $dest)) { @unlink($tmp); return false; }
    return true;
}

jsonOut(['ok' => false, 'error' => 'Unbekannte Aktion: ' . $action], 400);