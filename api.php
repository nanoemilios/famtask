<?php
/**
 * FamTask – api.php  v1.4
 * ─────────────────────────────────────────────────────────────────────────────
 * Einzige Backend-Datei für:
 *   • Installer   → api.php im Browser (erster Aufruf)
 *   • REST-API    → GET (Daten laden) / POST (Daten speichern)
 *   • Updater     → api.php?action=update
 *   • Statistiken → api.php?action=stats
 *   • iCal-Proxy  → api.php?action=ical  (Google Calendar Import – NEU)
 *   • Backup      → api.php?action=backup / api.php?action=restore
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('APP_VERSION', '1.4');
define('CFG',         __DIR__ . '/.famtask_cfg.php');
// ── PHP-Bootstrap: keine HTML-Fehler ins JSON, Timezone nur falls ungesetzt ──
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!ini_get('date.timezone')) { @date_default_timezone_set('UTC'); }
// ── SECURITY HEADERS ─────────────────────────────────────────
if (!headers_sent()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data: https:; font-src \'self\'; connect-src \'self\'; frame-ancestors \'none\';');
    header('Referrer-Policy: strict-no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
// ── Datenbank-Migrationen ────────────────────────────────────────────────────
// Neue Version: APP_VERSION erhöhen + neuen Eintrag hier hinzufügen
$MIGRATIONS = [
    '1.1.0' => "CREATE TABLE IF NOT EXISTS famtask_stats (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        day         DATE        NOT NULL,
        child_id    VARCHAR(20) NOT NULL,
        child_name  VARCHAR(80) NOT NULL,
        event_type  ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id      VARCHAR(80) NOT NULL,
        ref_label   VARCHAR(120),
        minutes     INT DEFAULT 0,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day),
        INDEX idx_child (child_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    '1.1.1' => "
        CREATE TABLE IF NOT EXISTS famtask_families (
            code        VARCHAR(10)  NOT NULL PRIMARY KEY,
            name        VARCHAR(80)  NOT NULL DEFAULT '',
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ALTER TABLE famtask_data
            ADD COLUMN IF NOT EXISTS family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
            DROP PRIMARY KEY,
            ADD PRIMARY KEY (family_code, data_key),
            ADD INDEX idx_family (family_code);
        ALTER TABLE famtask_stats
            ADD COLUMN IF NOT EXISTS family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
            ADD INDEX IF NOT EXISTS idx_family_stats (family_code);
        INSERT IGNORE INTO famtask_families (code, name) VALUES ('FAM001', 'Familie 1');
        UPDATE famtask_data  SET family_code='FAM001' WHERE family_code='default';
        UPDATE famtask_stats SET family_code='FAM001' WHERE family_code='default';
    ",

    '1.2.0' => "
        CREATE TABLE IF NOT EXISTS famtask_ical_events (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            family_code VARCHAR(10)  NOT NULL DEFAULT 'FAM001',
            uid         VARCHAR(200) NOT NULL,
            title       VARCHAR(200),
            date_start  DATE         NOT NULL,
            date_end    DATE,
            time_start  VARCHAR(10),
            description VARCHAR(500),
            source_url  VARCHAR(500),
            imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fam_uid (family_code, uid),
            INDEX idx_fc_date (family_code, date_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    "
    // v1.3: Keine Schema-Änderungen nötig
    // v1.4: Admin-PIN-Auth für api.php, Familien-Edit/Delete in App, MediaTypeModal, div. UI-Fixes
];

// ── Routing ──────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$action = strtolower($_GET['action'] ?? '');
$isJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
       || ($_SERVER['REQUEST_METHOD'] === 'POST')
       || isset($_SERVER['HTTP_X_REQUESTED_WITH']);

// Browser-Aufruf ohne Action → versteckt (Admin nur via ?action=admin)
if (!$isJson && $action === '') { http_response_code(404); echo 'Not Found'; exit; }

if ($action === 'admin') { showAdminPage(); exit; }

if ($action === 'install')  { handleInstall(); exit; }
if ($action === 'testdb')   { handleTestDb();  exit; }
if ($action === 'status')   { handleStatus();  exit; }
if ($action === 'update') {
    if (!$isJson) { showAdminPage('update'); exit; }
    handleUpdate($MIGRATIONS); exit;
}
if ($action === 'track') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleTrack(getDB(getCfg())); exit;
}
if ($action === 'stats') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    if (!$isJson) { showAdminPage('stats'); exit; }
    handleStats(getDB(getCfg())); exit;
}
if ($action === 'register') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleRegister(getDB(getCfg())); exit;
}
if ($action === 'verify') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleVerify(getDB(getCfg())); exit;
}
if ($action === 'families') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    if (!$isJson) { showAdminPage('families'); exit; }
    handleFamilies(getDB(getCfg())); exit;
}
if ($action === 'rename_family') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleRenameFamily(getDB(getCfg())); exit;
}
if ($action === 'delete_family') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleDeleteFamily(getDB(getCfg())); exit;
}
if ($action === 'settings') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    if (!$isJson) { showAdminPage('settings'); exit; }
    handleGetSettings(getDB(getCfg())); exit;
}
if ($action === 'save_settings') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleSaveSettings(getDB(getCfg())); exit;
}
// NEU: iCal-Import (Google Kalender)
if ($action === 'ical') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleIcal(getDB(getCfg())); exit;
}
if ($action === 'ytplaylist') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleYoutubePlaylist(getCfg()); exit;
}

// Backup / Restore (Admin)
if ($action === 'backup') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleBackup(getDB(getCfg())); exit;
}
if ($action === 'restore') {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Not configured']); exit; }
    handleRestore(getDB(getCfg())); exit;
}

// Daten-API
if (!file_exists(CFG)) {
    http_response_code(503);
    jsonOut(['error'=>'not_configured','msg'=>'Öffne api.php im Browser zur Einrichtung.']);
    exit;
}
$pdo = getDB(getCfg());
if ($_SERVER['REQUEST_METHOD'] === 'GET')  { handleGet($pdo);  exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') { handlePost($pdo); exit; }
http_response_code(405); echo 'Method Not Allowed';


// ══════════════════════════════════════════════════════════════════════════════
//  INSTALLER
// ══════════════════════════════════════════════════════════════════════════════
function handleInstall() {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $host = trim($data['host'] ?? '');
    $port = intval($data['port'] ?? 3306);
    $db   = trim($data['db']   ?? '');
    $user = trim($data['user'] ?? '');
    $pass = $data['pass'] ?? '';
    if (!$host || !$db || !$user) { jsonOut(['ok'=>false,'msg'=>'Host, Datenbank und Benutzer sind Pflicht.']); return; }
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass,
                       [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
    } catch (PDOException $e) { jsonOut(['ok'=>false,'msg'=>'Verbindung fehlgeschlagen: '.$e->getMessage()]); return; }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS famtask_data (
                family_code VARCHAR(10)  NOT NULL DEFAULT 'FAM001',
                data_key    VARCHAR(80)  NOT NULL,
                data_value  LONGTEXT,
                updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (family_code, data_key),
                INDEX idx_family (family_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE IF NOT EXISTS famtask_meta (
                meta_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
                meta_value VARCHAR(255)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE IF NOT EXISTS famtask_families (
                code       VARCHAR(10)  NOT NULL PRIMARY KEY,
                name       VARCHAR(80)  NOT NULL DEFAULT '',
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE IF NOT EXISTS famtask_stats (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                family_code VARCHAR(10)  NOT NULL DEFAULT 'FAM001',
                day         DATE         NOT NULL,
                child_id    VARCHAR(20)  NOT NULL,
                child_name  VARCHAR(80)  NOT NULL,
                event_type  ENUM('task_done','task_missed','media_used') NOT NULL,
                ref_id      VARCHAR(80)  NOT NULL,
                ref_label   VARCHAR(120),
                minutes     INT DEFAULT 0,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_day (day), INDEX idx_child (child_id), INDEX idx_fc (family_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            CREATE TABLE IF NOT EXISTS famtask_ical_events (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                family_code VARCHAR(10)  NOT NULL DEFAULT 'FAM001',
                uid         VARCHAR(200) NOT NULL,
                title       VARCHAR(200),
                date_start  DATE         NOT NULL,
                date_end    DATE,
                time_start  VARCHAR(10),
                description VARCHAR(500),
                source_url  VARCHAR(500),
                imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fam_uid (family_code, uid),
                INDEX idx_fc_date (family_code, date_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $pdo->prepare("INSERT INTO famtask_meta (meta_key,meta_value) VALUES ('db_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([APP_VERSION]);
        $pdo->exec("INSERT IGNORE INTO famtask_families (code,name) VALUES ('FAM001','Familie 1')");
    } catch (PDOException $e) { jsonOut(['ok'=>false,'msg'=>'Tabellen-Fehler: '.$e->getMessage()]); return; }
    $cfg = "<?php if(!defined('FAMTASK'))die('403'); return ".var_export(['host'=>$host,'port'=>$port,'db'=>$db,'user'=>$user,'pass'=>$pass],true)."; ?>";
    if (!file_put_contents(CFG, $cfg)) { jsonOut(['ok'=>false,'msg'=>'Konfigurationsdatei konnte nicht geschrieben werden.']); return; }
    $ht = __DIR__.'/.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Options -Indexes\nDirectoryIndex index.html index.php\n<FilesMatch \"^\\.famtask_cfg\\.php$\">\n  Require all denied\n</FilesMatch>\n");
    jsonOut(['ok'=>true,'msg'=>'Installation erfolgreich! FamTask ist einsatzbereit.','version'=>APP_VERSION]);
}


function handleTestDb() {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $host = trim($data['host'] ?? '');
    $port = intval($data['port'] ?? 3306);
    $db   = trim($data['db']   ?? '');
    $user = trim($data['user'] ?? '');
    $pass = $data['pass'] ?? '';
    if (!$host || !$db || !$user) { jsonOut(['ok'=>false,'msg'=>'Host, Datenbank und Benutzer sind Pflicht.']); return; }
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass,
                       [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
        jsonOut(['ok'=>true,'msg'=>'Verbindung erfolgreich']);
    } catch (PDOException $e) { jsonOut(['ok'=>false,'msg'=>'Verbindung fehlgeschlagen: '.$e->getMessage()]); }
}

// ══════════════════════════════════════════════════════════════════════════════
//  UPDATER
// ══════════════════════════════════════════════════════════════════════════════
function handleUpdate(array $migrations) {
    if (!file_exists(CFG)) { jsonOut(['ok'=>false,'msg'=>'Noch nicht installiert.']); return; }
    $pdo = getDB(getCfg()); $dbVer = getDbVersion($pdo); $ran = []; $errors = [];
    foreach ($migrations as $targetVer => $sql) {
        if (version_compare($dbVer, $targetVer, '<')) {
            try {
                $stmts = array_filter(array_map('trim', explode(';', $sql)));
                $hasSql = false;
                foreach ($stmts as $stmt) {
                    if ($stmt && strpos($stmt, 'SELECT') !== 0) {
                        $pdo->exec($stmt);
                        $hasSql = true;
                    }
                }
                // Auch wenn kein SQL ausgeführt wurde, Version aktualisieren
                $pdo->prepare("INSERT INTO famtask_meta (meta_key,meta_value) VALUES ('db_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([$targetVer]);
                $dbVer = $targetVer; $ran[] = $targetVer;
            } catch (PDOException $e) { $errors[] = "v$targetVer: ".$e->getMessage(); break; }
        }
    }
    if (empty($errors)) $pdo->prepare("INSERT INTO famtask_meta (meta_key,meta_value) VALUES ('db_version',?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute([APP_VERSION]);
    if ($errors) jsonOut(['ok'=>false,'msg'=>'Fehler: '.implode(', ',$errors),'ran'=>$ran]);
    elseif (empty($ran)) jsonOut(['ok'=>true,'msg'=>'Bereits aktuell.','db_version'=>APP_VERSION,'app_version'=>APP_VERSION]);
    else jsonOut(['ok'=>true,'msg'=>'Update erfolgreich! Migrationen: '.implode(', ',$ran),'db_version'=>APP_VERSION,'app_version'=>APP_VERSION]);
}

// ══════════════════════════════════════════════════════════════════════════════
//  STATUS
// ══════════════════════════════════════════════════════════════════════════════
function handleStatus() {
    if (!file_exists(CFG)) { jsonOut(['installed'=>false,'app_version'=>APP_VERSION]); return; }
    try {
        $pdo = getDB(getCfg()); $dbVer = getDbVersion($pdo);
        $rows = $pdo->query("SELECT COUNT(*) FROM famtask_data")->fetchColumn();
        jsonOut(['installed'=>true,'app_version'=>APP_VERSION,'db_version'=>$dbVer,
                 'data_rows'=>(int)$rows,'update_available'=>version_compare($dbVer,APP_VERSION,'<')]);
    } catch (PDOException $e) { jsonOut(['installed'=>true,'error'=>$e->getMessage()]); }
}

// ══════════════════════════════════════════════════════════════════════════════
//  FAMILIE HELPERS
// ══════════════════════════════════════════════════════════════════════════════
function getFamilyCode(): string {
    $code = trim($_GET['fc'] ?? $_SERVER['HTTP_X_FAMILY_CODE'] ?? '');
    $code = preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
    return ($code === '' || $code === 'DEFAULT') ? 'FAM001' : $code;
}

function ensureFamilyTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_families (
        code VARCHAR(10) NOT NULL PRIMARY KEY,
        name VARCHAR(80) NOT NULL DEFAULT '',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Alte Installs ohne family_code-Spalte upgraden
    try {
        $pdo->exec("ALTER TABLE famtask_data ADD COLUMN IF NOT EXISTS family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001'");
        $pdo->exec("ALTER TABLE famtask_data DROP PRIMARY KEY, ADD PRIMARY KEY (family_code,data_key), ADD INDEX IF NOT EXISTS idx_family (family_code)");
    } catch(PDOException $e) {}
}

function generateCode(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // kein O,0,1,I (Verwechslungsgefahr)
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
        $exists = $pdo->prepare("SELECT 1 FROM famtask_families WHERE code=?");
        $exists->execute([$code]);
    } while ($exists->fetch());
    return $code;
}

function handleRegister(PDO $pdo) {
    ensureFamilyTable($pdo);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? 'Meine Familie');
    $code = generateCode($pdo);
    $pdo->prepare("INSERT INTO famtask_families (code,name) VALUES (?,?)")->execute([$code,$name]);
    jsonOut(['ok'=>true,'code'=>$code,'name'=>$name]);
}

function handleVerify(PDO $pdo) {
    ensureFamilyTable($pdo);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = preg_replace('/[^A-Z0-9]/','',strtoupper(trim($body['code'] ?? '')));
    if (!$code) { jsonOut(['ok'=>false,'msg'=>'Kein Code angegeben']); return; }
    $stmt = $pdo->prepare("SELECT code,name FROM famtask_families WHERE code=?");
    $stmt->execute([$code]);
    $fam = $stmt->fetch();
    if ($fam) jsonOut(['ok'=>true,'code'=>$fam['code'],'name'=>$fam['name']]);
    else jsonOut(['ok'=>false,'msg'=>'Code nicht gefunden. Bitte prüfen.']);
}

function handleFamilies(PDO $pdo) {
    ensureFamilyTable($pdo);
    $stmt = $pdo->query("SELECT f.code,f.name,f.created_at,
        (SELECT COUNT(*) FROM famtask_data d WHERE d.family_code=f.code) as data_rows
        FROM famtask_families f ORDER BY f.created_at DESC");
    jsonOut(['ok'=>true,'families'=>$stmt->fetchAll()]);
}

function handleRenameFamily(PDO $pdo) {
    ensureFamilyTable($pdo);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = preg_replace('/[^A-Z0-9]/','',strtoupper(trim($body['fc'] ?? '')));
    $name = trim($body['name'] ?? '');
    if (!$code || !$name) { jsonOut(['ok'=>false,'msg'=>'Code und Name erforderlich']); return; }
    $stmt = $pdo->prepare("UPDATE famtask_families SET name=? WHERE code=?");
    $stmt->execute([$name, $code]);
    if ($stmt->rowCount() > 0) jsonOut(['ok'=>true]);
    else jsonOut(['ok'=>false,'msg'=>'Familie nicht gefunden']);
}

function handleDeleteFamily(PDO $pdo) {
    ensureFamilyTable($pdo);
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $code = preg_replace('/[^A-Z0-9]/','',strtoupper(trim($body['fc'] ?? '')));
    if (!$code) { jsonOut(['ok'=>false,'msg'=>'Code erforderlich']); return; }
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM famtask_data WHERE family_code=?")->execute([$code]);
    $pdo->prepare("DELETE FROM famtask_families WHERE code=?")->execute([$code]);
    $pdo->commit();
    jsonOut(['ok'=>true]);
}

function handleGetSettings(PDO $pdo) {
    $stmt = $pdo->query("SELECT meta_key, meta_value FROM famtask_meta WHERE meta_key LIKE 'setting_%'");
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $r) {
        $settings[substr($r['meta_key'], 8)] = $r['meta_value'];
    }
    // Defaults
    if (!isset($settings['default_pin'])) $settings['default_pin'] = '1234';
    if (!isset($settings['admin_pin'])) $settings['admin_pin'] = '1234';
    jsonOut(['ok'=>true, 'settings'=>$settings]);
}

function handleSaveSettings(PDO $pdo) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $allowed = ['default_pin', 'admin_pin'];
    foreach ($allowed as $key) {
        if (isset($body[$key])) {
            $val = trim($body[$key]);
            $pdo->prepare("INSERT INTO famtask_meta (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")
                ->execute(['setting_'.$key, $val]);
        }
    }
    jsonOut(['ok'=>true]);
}


// ══════════════════════════════════════════════════════════════════════════════
//  DATEN GET / POST
// ══════════════════════════════════════════════════════════════════════════════
function handleGet(PDO $pdo) {
    header('Content-Type: application/json');
    $fc = getFamilyCode() ?: 'FAM001';
    try {
        // Sicherstellen dass family_code Spalte existiert (nur für Alt-Installs)
        ensureFamilyColumn($pdo);
        $stmt = $pdo->prepare("SELECT data_key,data_value FROM famtask_data WHERE family_code=?");
        $stmt->execute([$fc]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $out[$row['data_key']] = $row['data_value'];
        echo json_encode($out);
    } catch (PDOException $e) { http_response_code(500); echo json_encode(['error'=>$e->getMessage()]); }
}

function handlePost(PDO $pdo) {
    header('Content-Type: application/json');
    $fc   = getFamilyCode() ?: 'FAM001';
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error'=>'Ungültiges JSON']); return; }
    try {
        ensureFamilyColumn($pdo);
        $stmt = $pdo->prepare("INSERT INTO famtask_data (family_code,data_key,data_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data_value=VALUES(data_value),updated_at=CURRENT_TIMESTAMP");
        $pdo->beginTransaction();
        foreach ($body as $k => $v) $stmt->execute([$fc,(string)$k,is_string($v)?$v:json_encode($v)]);
        $pdo->commit();
        echo json_encode(['ok'=>true]);
    } catch (PDOException $e) { $pdo->rollBack(); http_response_code(500); echo json_encode(['error'=>$e->getMessage()]); }
}

// ══════════════════════════════════════════════════════════════════════════════
//  TRACK EVENT
// ══════════════════════════════════════════════════════════════════════════════
function handleTrack(PDO $pdo) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); jsonOut(['ok'=>false]); return; }
    $fc = getFamilyCode() ?: 'FAM001';
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_stats (
        id INT AUTO_INCREMENT PRIMARY KEY, family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
        day DATE NOT NULL, child_id VARCHAR(20) NOT NULL, child_name VARCHAR(80) NOT NULL,
        event_type ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id VARCHAR(80) NOT NULL, ref_label VARCHAR(120), minutes INT DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day), INDEX idx_child (child_id), INDEX idx_fc (family_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT INTO famtask_stats (family_code,day,child_id,child_name,event_type,ref_id,ref_label,minutes) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$fc,$body['day']??date('Y-m-d'),$body['child_id']??'',$body['child_name']??'',$body['event_type']??'task_done',$body['ref_id']??'',$body['ref_label']??'',intval($body['minutes']??0)]);
    jsonOut(['ok'=>true]);
}


// ══════════════════════════════════════════════════════════════════════════════
//  ICAL-IMPORT  (Google Calendar Proxy) — NEU in v1.2
//
//  Liest einen öffentlichen iCal-Feed (Google Kalender, Apple, etc.) und
//  speichert die Events in famtask_ical_events. Die React-App liest diese
//  Events dann über den normalen GET-Endpunkt als ft-ical-Schlüssel.
//
//  Aufruf (POST):
//    api.php?action=ical&fc=XXXXXX
//    Body: { "url": "https://calendar.google.com/calendar/ical/..." }
//
//  Oder konfiguriert in .famtask_cfg.php:
//    'ical_urls' => ['Familienkalender' => 'https://...', ...]
// ══════════════════════════════════════════════════════════════════════════════
function handleIcal(PDO $pdo) {
    $fc = getFamilyCode() ?: 'FAM001';

    // Tabelle sicherstellen
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_ical_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
        uid VARCHAR(200) NOT NULL,
        title VARCHAR(200), date_start DATE NOT NULL, date_end DATE,
        time_start VARCHAR(10), description VARCHAR(500), source_url VARCHAR(500),
        imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fam_uid (family_code, uid),
        INDEX idx_fc_date (family_code, date_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // URL aus Body oder aus Konfiguration
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $urls = [];

    if (!empty($body['url'])) {
        $urls['Importiert'] = trim($body['url']);
    } else {
        // Aus .famtask_cfg.php lesen (optional: 'ical_urls' Array)
        if (file_exists(CFG)) {
            define('FAMTASK', true);
            $cfg = require CFG;
            if (!empty($cfg['ical_urls']) && is_array($cfg['ical_urls'])) {
                $urls = $cfg['ical_urls'];
            }
        }
    }

    if (empty($urls)) {
        jsonOut(['ok'=>false,'msg'=>'Keine iCal-URL angegeben. Entweder im POST-Body {"url":"..."} oder in .famtask_cfg.php als ical_urls konfigurieren.']);
        return;
    }

    $imported = 0; $errors = [];
    foreach ($urls as $label => $url) {
        // Sicherheits-Check: nur https:// erlaubt, kein localhost
        if (!preg_match('#^https://#i', $url)) { $errors[] = "$label: Nur HTTPS-URLs erlaubt"; continue; }
        if (preg_match('#localhost|127\.0\.0\.1|::1#i', $url)) { $errors[] = "$label: Lokale URLs nicht erlaubt"; continue; }

        // iCal laden (max 2MB, 8s Timeout) – cURL mit fopen-Fallback
        $raw = httpGet($url, 8);
        if ($raw === false || strlen($raw) < 10) { $errors[] = "$label: Konnte URL nicht laden"; continue; }
        if (strlen($raw) > 2 * 1024 * 1024)      { $errors[] = "$label: Datei zu gross (max 2MB)"; continue; }

        // iCal parsen
        $events = parseIcal($raw, $url);
        if (empty($events)) { $errors[] = "$label: Keine Events gefunden"; continue; }

        // Events in DB speichern (INSERT OR UPDATE)
        $stmt = $pdo->prepare("INSERT INTO famtask_ical_events (family_code,uid,title,date_start,date_end,time_start,description,source_url)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE title=VALUES(title),date_start=VALUES(date_start),date_end=VALUES(date_end),
            time_start=VALUES(time_start),description=VALUES(description),imported_at=CURRENT_TIMESTAMP");

        foreach ($events as $ev) {
            $stmt->execute([$fc, $ev['uid'], $ev['title'], $ev['date_start'], $ev['date_end'], $ev['time_start'], $ev['description'], $url]);
            $imported++;
        }
    }

    // Events der letzten 90 Tage als JSON zurückgeben (für React-App)
    $sel = $pdo->prepare("SELECT uid,title,date_start,date_end,time_start,description,source_url
        FROM famtask_ical_events WHERE family_code=? AND date_start >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND date_start <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY date_start ASC");
    $sel->execute([$fc]);
    $events = $sel->fetchAll();

    jsonOut(['ok'=>true,'imported'=>$imported,'errors'=>$errors,'events'=>$events,'count'=>count($events)]);
}

// ── iCal Parser (minimaler RFC-5545 Parser) ──────────────────────────────────
// Parst die wichtigsten Felder: SUMMARY, DTSTART, DTEND, UID, DESCRIPTION
function parseIcal(string $raw, string $sourceUrl): array {
    // Zeilenumbrüche normalisieren (RFC 5545: CRLF, auch CR oder LF möglich)
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    // Folded lines zusammenführen (Leerzeichen/Tab am Zeilenanfang = Fortsetzung)
    $raw = preg_replace("/\n[ \t]/", '', $raw);

    $events = []; $current = null; $inEvent = false;
    foreach (explode("\n", $raw) as $line) {
        $line = rtrim($line);
        if ($line === 'BEGIN:VEVENT') { $inEvent = true; $current = []; continue; }
        if ($line === 'END:VEVENT' && $inEvent) {
            if (!empty($current['date_start'])) $events[] = $current;
            $inEvent = false; $current = null; continue;
        }
        if (!$inEvent || $current === null) continue;

        // Property und Wert trennen (Property kann Parameter haben: DTSTART;TZID=...)
        $colon = strpos($line, ':');
        if ($colon === false) continue;
        $prop  = strtoupper(substr($line, 0, $colon));
        $value = substr($line, $colon + 1);

        // Property-Name ohne Parameter
        $propName = explode(';', $prop)[0];

        switch ($propName) {
            case 'UID':
                $current['uid'] = substr(trim($value), 0, 200);
                break;
            case 'SUMMARY':
                $current['title'] = substr(icalUnescape($value), 0, 200);
                break;
            case 'DESCRIPTION':
                $current['description'] = substr(icalUnescape($value), 0, 500);
                break;
            case 'DTSTART':
                [$date, $time] = icalParseDateTime($value, $prop);
                $current['date_start'] = $date;
                $current['time_start'] = $time;
                break;
            case 'DTEND':
                [$date] = icalParseDateTime($value, $prop);
                // DTEND ist exklusiv → einen Tag zurück für Ganztages-Events
                if (strlen($value) === 8 && strpos($prop, 'TZID') === false && strpos($value, 'T') === false) {
                    try {
                        $dt = DateTime::createFromFormat('Ymd', $value);
                        if ($dt !== false) { $dt->modify('-1 day'); $date = $dt->format('Y-m-d'); }
                    } catch (Exception $e) {}
                }
                $current['date_end'] = $date;
                break;
        }
    }
    // Fehlende Felder auffüllen
    foreach ($events as &$ev) {
        $ev['uid']         = $ev['uid']         ?? md5($sourceUrl.($ev['date_start']??'').($ev['title']??''));
        $ev['title']       = $ev['title']        ?? 'Termin';
        $ev['date_end']    = $ev['date_end']     ?? null;
        $ev['time_start']  = $ev['time_start']   ?? null;
        $ev['description'] = $ev['description']  ?? null;
    }
    return $events;
}

// iCal-Datum parsen: 20240315 → 2024-03-15 / 20240315T143000Z → (2024-03-15, 14:30)
function icalParseDateTime(string $value, string $prop): array {
    $value = trim($value);
    if (strlen($value) === 8 && ctype_digit($value)) {
        // Ganztages-Datum
        return [substr($value,0,4).'-'.substr($value,4,2).'-'.substr($value,6,2), null];
    }
    if (preg_match('/^(\d{8})T(\d{6})/', $value, $m)) {
        $date = substr($m[1],0,4).'-'.substr($m[1],4,2).'-'.substr($m[1],6,2);
        $time = substr($m[2],0,2).':'.substr($m[2],2,2);
        return [$date, $time];
    }
    return [date('Y-m-d'), null]; // Fallback: heute
}

// iCal-Escape-Sequenzen auflösen
function icalUnescape(string $s): string {
    return str_replace(['\,','\;','\\n','\\N'],[',',';',"\n","\n"], $s);
}

// ══════════════════════════════════════════════════════════════════════════════
//  YOUTUBE PLAYLIST METADATA (Queue für Audio-Player)
// ══════════════════════════════════════════════════════════════════════════════
function handleYoutubePlaylist(array $cfg): void {
    $playlistId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['playlist_id'] ?? ''));
    if ($playlistId === '') { jsonOut(['ok'=>false,'msg'=>'playlist_id fehlt']); return; }

    $apiKey = trim($cfg['youtube_api_key'] ?? '');
    if ($apiKey === '') { jsonOut(['ok'=>false,'msg'=>'YouTube API Key fehlt']); return; }

    $url = 'https://www.googleapis.com/youtube/v3/playlistItems'
        .'?part=snippet,contentDetails'
        .'&maxResults=50'
        .'&playlistId='.rawurlencode($playlistId)
        .'&key='.rawurlencode($apiKey);

    $raw = httpGet($url, 8);
    if ($raw === false) { jsonOut(['ok'=>false,'msg'=>'YouTube API nicht erreichbar']); return; }

    $json = json_decode($raw, true);
    if (!is_array($json)) { jsonOut(['ok'=>false,'msg'=>'Ungültige Antwort von YouTube']); return; }
    if (!empty($json['error'])) {
        $msg = $json['error']['message'] ?? 'YouTube API Fehler';
        jsonOut(['ok'=>false,'msg'=>$msg]); return;
    }

    $items = [];
    foreach (($json['items'] ?? []) as $it) {
        $sn = $it['snippet'] ?? [];
        $thumbs = $sn['thumbnails'] ?? [];
        $thumb = $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? '';
        $items[] = [
            'videoId' => $sn['resourceId']['videoId'] ?? '',
            'title'   => $sn['title'] ?? 'Unbekannt',
            'channel' => $sn['videoOwnerChannelTitle'] ?? ($sn['channelTitle'] ?? ''),
            'thumb'   => $thumb,
        ];
    }
    $items = array_values(array_filter($items, fn($x)=>$x['videoId']!==''));

    jsonOut([
        'ok'=>true,
        'playlist_id'=>$playlistId,
        'count'=>count($items),
        'items'=>$items
    ]);
}


// ══════════════════════════════════════════════════════════════════════════════
//  BACKUP / RESTORE (Admin)
// ══════════════════════════════════════════════════════════════════════════════
function ensureBackupTables(PDO $pdo): void {
    // Base-Tabellen (falls noch nicht vorhanden)
    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_data (
        family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
        data_key    VARCHAR(80) NOT NULL,
        data_value  LONGTEXT,
        updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (family_code, data_key),
        INDEX idx_family (family_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("ALTER TABLE famtask_data
        ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_meta (
        meta_key   VARCHAR(80) NOT NULL PRIMARY KEY,
        meta_value VARCHAR(255)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // families + sicherstellen, dass family_code/PK korrekt sind (Alt-Installs)
    ensureFamilyTable($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_stats (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
        day         DATE NOT NULL,
        child_id    VARCHAR(20) NOT NULL,
        child_name  VARCHAR(80) NOT NULL,
        event_type  ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id      VARCHAR(80) NOT NULL,
        ref_label   VARCHAR(120),
        minutes     INT DEFAULT 0,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day),
        INDEX idx_child (child_id),
        INDEX idx_fc (family_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_ical_events (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
        uid         VARCHAR(200) NOT NULL,
        title       VARCHAR(200),
        date_start  DATE NOT NULL,
        date_end    DATE,
        time_start  VARCHAR(10),
        description VARCHAR(500),
        source_url  VARCHAR(500),
        imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fam_uid (family_code, uid),
        INDEX idx_fc_date (family_code, date_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function handleBackup(PDO $pdo): void {
    $scope = strtolower(trim($_GET['scope'] ?? 'all'));
    if (!in_array($scope, ['all', 'family'], true)) $scope = 'all';

    $familyCode = null;
    if ($scope === 'family') {
        // Für family-backup muss explizit ein fc angegeben sein.
        $rawFc = $_GET['fc'] ?? ($_SERVER['HTTP_X_FAMILY_CODE'] ?? '');
        $rawFc = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$rawFc)));
        if ($rawFc === '') {
            http_response_code(400);
            jsonOut(['ok'=>false,'msg'=>'fc erforderlich für scope=family']);
            return;
        }
        $familyCode = $rawFc;
    }

    // Falls Tabellen bei Alt-Installs fehlen: sicherstellen.
    ensureBackupTables($pdo);

    $exportedAt = date('c');
    $dbVer = getDbVersion($pdo);

    $meta = $pdo->query("SELECT meta_key,meta_value FROM famtask_meta ORDER BY meta_key ASC")->fetchAll();

    $families = [];
    if ($scope === 'family') {
        $st = $pdo->prepare("SELECT code,name,created_at FROM famtask_families WHERE code=? ORDER BY created_at DESC");
        $st->execute([$familyCode]);
    } else {
        $st = $pdo->query("SELECT code,name,created_at FROM famtask_families ORDER BY created_at DESC");
    }
    $families = $st->fetchAll();

    $whereSql = '';
    $params = [];
    if ($scope === 'family') { $whereSql = " WHERE family_code=?"; $params = [$familyCode]; }

    $data = [];
    $st = $pdo->prepare("SELECT family_code,data_key,data_value,updated_at FROM famtask_data".$whereSql);
    $st->execute($params);
    $data = $st->fetchAll();

    $stats = [];
    $st = $pdo->prepare("SELECT family_code,day,child_id,child_name,event_type,ref_id,ref_label,minutes,created_at FROM famtask_stats".$whereSql." ORDER BY created_at DESC");
    $st->execute($params);
    $stats = $st->fetchAll();

    $ical = [];
    $st = $pdo->prepare("SELECT family_code,uid,title,date_start,date_end,time_start,description,source_url,imported_at FROM famtask_ical_events".$whereSql." ORDER BY imported_at DESC");
    $st->execute($params);
    $ical = $st->fetchAll();

    $payload = [
        'format' => 'famtask-backup-v1',
        'app_version' => APP_VERSION,
        'db_version' => $dbVer,
        'exported_at' => $exportedAt,
        'scope' => $scope,
        'family_code' => $familyCode,
        'meta' => $meta,
        'families' => $families,
        'data' => $data,
        'stats' => $stats,
        'ical_events' => $ical,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        jsonOut(['ok'=>false,'msg'=>'Backup konnte nicht serialisiert werden.']);
        return;
    }

    $filename = 'famtask-backup-'.date('Ymd-His').'-v'.APP_VERSION.'.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo $json;
}

function handleRestore(PDO $pdo): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        jsonOut(['ok'=>false,'msg'=>'restore via POST']);
        return;
    }

    $raw = null;
    if (!empty($_FILES['backup']['tmp_name'])) {
        $maxBytes = 20 * 1024 * 1024;
        if (!empty($_FILES['backup']['size']) && intval($_FILES['backup']['size']) > $maxBytes) {
            http_response_code(413);
            jsonOut(['ok'=>false,'msg'=>'Backup-Datei zu gross (max 20MB)']);
            return;
        }
        $raw = file_get_contents($_FILES['backup']['tmp_name']);
    } else {
        $raw = file_get_contents('php://input');
    }

    if (!is_string($raw) || trim($raw) === '') {
        http_response_code(400);
        jsonOut(['ok'=>false,'msg'=>'Keine Backup-Datei/JSON uebergeben.']);
        return;
    }

    if (strlen($raw) > (25 * 1024 * 1024)) {
        http_response_code(413);
        jsonOut(['ok'=>false,'msg'=>'Backup-JSON zu gross (max 25MB)']);
        return;
    }

    $backup = json_decode($raw, true);
    if (!is_array($backup)) {
        http_response_code(400);
        jsonOut(['ok'=>false,'msg'=>'Ungültiges Backup-JSON']);
        return;
    }

    if (($backup['format'] ?? '') !== 'famtask-backup-v1') {
        http_response_code(400);
        jsonOut(['ok'=>false,'msg'=>'Unbekanntes Backup-Format']);
        return;
    }

    // Optional: beim Restore Meta komplett ersetzen.
    $clearMeta = isset($_POST['clear_meta']) && strval($_POST['clear_meta']) === '1';

    $scope = strtolower(trim($backup['scope'] ?? 'all'));
    if (!in_array($scope, ['all', 'family'], true)) $scope = 'all';
    $familyCode = $backup['family_code'] ?? null;
    if ($scope === 'family' && (!is_string($familyCode) || trim($familyCode) === '')) {
        http_response_code(400);
        jsonOut(['ok'=>false,'msg'=>'Backup scope=family erfordert family_code']);
        return;
    }

    try {
        ensureBackupTables($pdo);

        $pdo->beginTransaction();

        if ($scope === 'family') {
            $fc = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string)$familyCode)));
            $pdo->prepare("DELETE FROM famtask_data WHERE family_code=?")->execute([$fc]);
            $pdo->prepare("DELETE FROM famtask_stats WHERE family_code=?")->execute([$fc]);
            $pdo->prepare("DELETE FROM famtask_families WHERE code=?")->execute([$fc]);
            $pdo->prepare("DELETE FROM famtask_ical_events WHERE family_code=?")->execute([$fc]);
        } else {
            $pdo->exec("DELETE FROM famtask_data");
            $pdo->exec("DELETE FROM famtask_stats");
            $pdo->exec("DELETE FROM famtask_families");
            $pdo->exec("DELETE FROM famtask_ical_events");
        }

        if ($clearMeta) {
            $pdo->exec("DELETE FROM famtask_meta");
        }

        // Meta (Upsert)
        $meta = $backup['meta'] ?? [];
        $stMeta = $pdo->prepare("INSERT INTO famtask_meta (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
        foreach ($meta as $m) {
            if (!is_array($m)) continue;
            $key = (string)($m['meta_key'] ?? '');
            $val = isset($m['meta_value']) ? (string)$m['meta_value'] : '';
            if ($key === '') continue;
            $stMeta->execute([$key, $val]);
        }

        // Familien (Upsert)
        $families = $backup['families'] ?? [];
        $stFam = $pdo->prepare("INSERT INTO famtask_families (code,name,created_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), created_at=VALUES(created_at)");
        foreach ($families as $f) {
            if (!is_array($f)) continue;
            $code = (string)($f['code'] ?? '');
            if ($code === '') continue;
            $name = (string)($f['name'] ?? '');
            $createdAt = (string)($f['created_at'] ?? date('Y-m-d H:i:s'));
            $stFam->execute([$code, $name, $createdAt]);
        }

        // Daten
        $data = $backup['data'] ?? [];
        $stData = $pdo->prepare("INSERT INTO famtask_data (family_code,data_key,data_value,updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE data_value=VALUES(data_value), updated_at=VALUES(updated_at)");
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $fc = (string)($row['family_code'] ?? '');
            $k = (string)($row['data_key'] ?? '');
            if ($fc === '' || $k === '') continue;
            $v = isset($row['data_value']) ? (string)$row['data_value'] : null;
            $upd = (string)($row['updated_at'] ?? date('Y-m-d H:i:s'));
            $stData->execute([$fc, $k, $v, $upd]);
        }

        // Stats
        $stats = $backup['stats'] ?? [];
        $stStats = $pdo->prepare("INSERT INTO famtask_stats (family_code,day,child_id,child_name,event_type,ref_id,ref_label,minutes,created_at)
            VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($stats as $row) {
            if (!is_array($row)) continue;
            $fc = (string)($row['family_code'] ?? '');
            if ($fc === '') continue;
            $stStats->execute([
                $fc,
                (string)($row['day'] ?? ''),
                (string)($row['child_id'] ?? ''),
                (string)($row['child_name'] ?? ''),
                (string)($row['event_type'] ?? 'task_done'),
                (string)($row['ref_id'] ?? ''),
                isset($row['ref_label']) ? (string)$row['ref_label'] : null,
                intval($row['minutes'] ?? 0),
                (string)($row['created_at'] ?? date('Y-m-d H:i:s'))
            ]);
        }

        // iCal Events
        $ical = $backup['ical_events'] ?? [];
        $stIcal = $pdo->prepare("INSERT INTO famtask_ical_events
            (family_code,uid,title,date_start,date_end,time_start,description,source_url,imported_at)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                title=VALUES(title),
                date_start=VALUES(date_start),
                date_end=VALUES(date_end),
                time_start=VALUES(time_start),
                description=VALUES(description),
                source_url=VALUES(source_url),
                imported_at=VALUES(imported_at)");
        foreach ($ical as $row) {
            if (!is_array($row)) continue;
            $fc = (string)($row['family_code'] ?? '');
            $uid = (string)($row['uid'] ?? '');
            if ($fc === '' || $uid === '') continue;
            $stIcal->execute([
                $fc,
                $uid,
                isset($row['title']) ? (string)$row['title'] : null,
                (string)($row['date_start'] ?? date('Y-m-d')),
                isset($row['date_end']) ? $row['date_end'] : null,
                isset($row['time_start']) ? (string)$row['time_start'] : null,
                isset($row['description']) ? (string)$row['description'] : null,
                isset($row['source_url']) ? (string)$row['source_url'] : null,
                (string)($row['imported_at'] ?? date('Y-m-d H:i:s'))
            ]);
        }

        $pdo->commit();

        jsonOut([
            'ok' => true,
            'msg' => 'Backup erfolgreich wiederhergestellt',
            'scope' => $scope,
            'app_version' => $backup['app_version'] ?? null,
            'db_version' => $backup['db_version'] ?? null
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        jsonOut(['ok'=>false,'msg'=>'Restore fehlgeschlagen: '.$e->getMessage()]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  STATS  (JSON für Admin-Seite)
// ══════════════════════════════════════════════════════════════════════════════
function handleStats(PDO $pdo) {
    $days  = max(1, min(90, intval($_GET['days'] ?? 30)));
    $child = $_GET['child'] ?? '';
    $fc    = getFamilyCode() ?: 'FAM001';

    $pdo->exec("CREATE TABLE IF NOT EXISTS famtask_stats (
        id INT AUTO_INCREMENT PRIMARY KEY, family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001',
        day DATE NOT NULL, child_id VARCHAR(20) NOT NULL, child_name VARCHAR(80) NOT NULL,
        event_type ENUM('task_done','task_missed','media_used') NOT NULL,
        ref_id VARCHAR(80) NOT NULL, ref_label VARCHAR(120), minutes INT DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_day (day), INDEX idx_child (child_id), INDEX idx_fc (family_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $where  = "family_code=? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params = [$fc, $days];
    if ($child) { $where .= " AND child_id=?"; $params[] = $child; }

    $daily = $pdo->prepare("SELECT day,child_id,child_name,
        SUM(CASE WHEN event_type='task_done'   THEN 1 ELSE 0 END) AS tasks_done,
        SUM(CASE WHEN event_type='task_missed' THEN 1 ELSE 0 END) AS tasks_missed,
        SUM(CASE WHEN event_type='media_used'  THEN minutes ELSE 0 END) AS media_mins
        FROM famtask_stats WHERE $where GROUP BY day,child_id,child_name ORDER BY day DESC,child_name");
    $daily->execute($params);

    $topTasks = $pdo->prepare("SELECT ref_label,child_name,
        SUM(CASE WHEN event_type='task_done'   THEN 1 ELSE 0 END) AS done,
        SUM(CASE WHEN event_type='task_missed' THEN 1 ELSE 0 END) AS missed
        FROM famtask_stats WHERE $where AND event_type IN ('task_done','task_missed')
        GROUP BY ref_id,ref_label,child_id,child_name ORDER BY done DESC LIMIT 20");
    $topTasks->execute($params);

    $mediaBreakdown = $pdo->prepare("SELECT ref_label,child_name,SUM(minutes) AS total_mins
        FROM famtask_stats WHERE $where AND event_type='media_used'
        GROUP BY ref_id,ref_label,child_id,child_name ORDER BY total_mins DESC");
    $mediaBreakdown->execute($params);

    $totals = $pdo->prepare("SELECT child_name,
        SUM(CASE WHEN event_type='task_done'   THEN 1 ELSE 0 END) AS tasks_done,
        SUM(CASE WHEN event_type='task_missed' THEN 1 ELSE 0 END) AS tasks_missed,
        SUM(CASE WHEN event_type='media_used'  THEN minutes ELSE 0 END) AS media_mins
        FROM famtask_stats WHERE $where GROUP BY child_id,child_name ORDER BY child_name");
    $totals->execute($params);

    jsonOut(['ok'=>true,'days'=>$days,'daily'=>$daily->fetchAll(),
             'top_tasks'=>$topTasks->fetchAll(),'media_breakdown'=>$mediaBreakdown->fetchAll(),'totals'=>$totals->fetchAll()]);
}

// ══════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════════════════
function getCfg(): array {
    if (!defined('FAMTASK')) define('FAMTASK', true);
    return require CFG;
}
function getDB(array $c): PDO {
    return new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['db']};charset=utf8mb4",
                   $c['user'], $c['pass'],
                   [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function getDbVersion(PDO $pdo): string {
    try { $s=$pdo->query("SELECT meta_value FROM famtask_meta WHERE meta_key='db_version'"); return $s->fetchColumn()?:'0.0.0'; }
    catch (PDOException $e) { return '0.0.0'; }
}
// HTTP-Fetch mit cURL, sonst fopen-Fallback (max. Kompatibilität mit Shared Hosting)
function httpGet(string $url, int $timeout = 8, array $headers = []): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'FamTask/1.4',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $code >= 400) ? false : $body;
    }
    $ctx = stream_context_create(['http' => [
        'timeout'      => $timeout,
        'user_agent'   => 'FamTask/1.4',
        'header'       => implode("\r\n", $headers),
        'ignore_errors'=> true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body === false) ? false : $body;
}
// family_code-Spalte nur einmalig für Alt-Installs ergänzen (nicht bei jedem Request)
function ensureFamilyColumn(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (version_compare(getDbVersion($pdo), '1.1.1', '<')) {
            $pdo->exec("ALTER TABLE famtask_data ADD COLUMN IF NOT EXISTS family_code VARCHAR(10) NOT NULL DEFAULT 'FAM001'");
        }
    } catch (PDOException $e) {}
}
function jsonOut(array $data): void { header('Content-Type: application/json'); echo json_encode($data); }


// ══════════════════════════════════════════════════════════════════════════════
//  ADMIN-SEITE  (Browser-UI für Installer, Status, Update, Statistiken)
// ══════════════════════════════════════════════════════════════════════════════
function showAdminPage(string $tab = ''): void {
    $configured = file_exists(CFG);
    $status = ['installed'=>false,'app_version'=>APP_VERSION,'db_version'=>'–','data_rows'=>0,'update_available'=>false];
    if ($configured) {
        try {
            if (!defined('FAMTASK')) define('FAMTASK', true);
            $cfg   = require CFG;
            $pdo   = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
                             $cfg['user'],$cfg['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $dbVer = getDbVersion($pdo);
            $rows  = $pdo->query("SELECT COUNT(*) FROM famtask_data")->fetchColumn();
            $status = ['installed'=>true,'app_version'=>APP_VERSION,'db_version'=>$dbVer,
                       'data_rows'=>(int)$rows,'update_available'=>version_compare($dbVer,APP_VERSION,'<'),
                       'db_host'=>$cfg['host'],'db_name'=>$cfg['db']];
        } catch (PDOException $e) { $status=['installed'=>true,'error'=>$e->getMessage(),'app_version'=>APP_VERSION]; }
    }

    // ── Admin-PIN Auth ─────────────────────────────────────────────────────
    $authOk = !$configured; // Install-Seite ohne Auth
    if ($configured && $status['installed'] && !isset($status['error'])) {
        $stored = '1234';
        try {
            $s = $pdo->query("SELECT meta_value FROM famtask_meta WHERE meta_key='setting_admin_pin'");
            $v = $s->fetchColumn();
            if ($v) $stored = $v;
        } catch (PDOException $e) {}
        $expectedHash = sha1('ft_admin_' . $stored);

        // Logout
        if (isset($_GET['logout'])) {
            setcookie('ft_admin_auth', '', time() - 3600, '/');
            $authOk = false;
        }
        // Cookie check
        elseif (isset($_COOKIE['ft_admin_auth']) && $_COOKIE['ft_admin_auth'] === $expectedHash) {
            $authOk = true;
        }
        // POST login
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_pin'])) {
            if ($_POST['admin_pin'] === $stored) {
                setcookie('ft_admin_auth', $expectedHash, time() + 86400 * 30, '/');
                echo '<script>location.href="?action=admin";</script>';
                exit;
            } else {
                $authError = 'Falscher PIN';
            }
        }
    }

    if (!$authOk) {
        // Login-Formular anzeigen
        ?><!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/><link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%23FF6B6B'/%3E%3Cstop offset='100%25' stop-color='%23FFE66D'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='6' fill='%230D0E1A'/%3E%3Crect x='2' y='2' width='28' height='28' rx='5' fill='url(%23g)'/%3E%3Ctext x='16' y='22' font-family='Arial' font-size='20' font-weight='900' fill='%23fff' text-anchor='middle'%3EF%3C/text%3E%3C/svg%3E"/><title>FamTask – Admin</title><style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Segoe UI',Arial,sans-serif;background:#0D0E1A;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
  .card{background:#161728;border:1.5px solid rgba(255,255,255,.08);border-radius:18px;padding:28px 32px;width:100%;max-width:400px}
    .logo{font-size:36px;font-weight:900;background:linear-gradient(135deg,#FF6B6B,#FFE66D);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-align:center;margin-bottom:4px}
    .sub{text-align:center;color:rgba(255,255,255,.35);font-size:13px;margin-bottom:24px}
    label{display:block;font-size:11px;font-weight:800;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
    input{width:100%;background:#0D0E1A;border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#fff;font-size:22px;font-weight:900;padding:12px 14px;outline:none;text-align:center;letter-spacing:8px;font-family:monospace}
    input:focus{border-color:#FF6B6B}
    .btn{width:100%;padding:13px;border:none;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;margin-top:18px}
    .btn-pri{background:linear-gradient(135deg,#FF6B6B,#FF8E53);color:#fff}
    .btn-sec{background:rgba(78,205,196,.12);border:1.5px solid rgba(78,205,196,.3);color:#4ECDC4}
    .err{color:#FF6B6B;font-size:12px;font-weight:700;text-align:center;margin-top:10px}
    </style></head><body><div class="card"><div class="logo">FamTask</div><div class="sub">Admin-PIN eingeben</div>
    <form method="post"><label>Admin-PIN (4 Ziffern)</label><input type="password" name="admin_pin" maxlength="4" inputmode="numeric" autofocus/><button class="btn btn-pri">Freischalten</button></form>
    <?php if (isset($authError)): ?><div class="err"><?= htmlspecialchars($authError) ?></div><?php endif; ?>
    </div></body></html><?php
    exit;
    }
    // ── Ende Auth ──────────────────────────────────────────────────────────

    $sj = json_encode($status);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%23FF6B6B'/%3E%3Cstop offset='100%25' stop-color='%23FFE66D'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='32' height='32' rx='6' fill='%230D0E1A'/%3E%3Crect x='2' y='2' width='28' height='28' rx='5' fill='url(%23g)'/%3E%3Ctext x='16' y='22' font-family='Arial' font-size='20' font-weight='900' fill='%23fff' text-anchor='middle'%3EF%3C/text%3E%3C/svg%3E"/>
  <title>FamTask – Admin</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',Arial,sans-serif;background:#0D0E1A;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;padding:20px}
    .card{background:#161728;border:1.5px solid rgba(255,255,255,.08);border-radius:18px;padding:28px 32px;width:100%;max-width:560px;margin-top:28px}
    .logo{font-size:36px;font-weight:900;background:linear-gradient(135deg,#FF6B6B,#FFE66D);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-align:center;margin-bottom:4px}
    .sub{text-align:center;color:rgba(255,255,255,.35);font-size:13px;margin-bottom:24px}
    .tabs{display:flex;gap:6px;margin-bottom:22px;flex-wrap:wrap}
    .tab{flex:1;min-width:80px;padding:9px;background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);border-radius:10px;color:rgba(255,255,255,.4);font-size:11px;font-weight:700;cursor:pointer;text-align:center;transition:all .15s}
    .tab.active{background:rgba(255,107,107,.12);border-color:rgba(255,107,107,.4);color:#FF6B6B}
    .section{display:none}.section.active{display:block}
    label{display:block;font-size:11px;font-weight:800;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;margin-top:14px}
    input{width:100%;background:#0D0E1A;border:1.5px solid rgba(255,255,255,.1);border-radius:10px;color:#fff;font-size:14px;padding:11px 14px;outline:none;transition:border-color .15s}
    input:focus{border-color:#FF6B6B}
    .row{display:flex;gap:10px}.row .field{flex:1}
    .btn{width:100%;padding:13px;border:none;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;margin-top:18px;transition:all .15s}
    .btn-pri{background:linear-gradient(135deg,#FF6B6B,#FF8E53);color:#fff}
    .btn-sec{background:rgba(78,205,196,.12);border:1.5px solid rgba(78,205,196,.3);color:#4ECDC4}
    .btn-disabled{opacity:.4;pointer-events:none}
    .btn:active{transform:scale(.97)}
    .msg{margin-top:14px;padding:12px 16px;border-radius:11px;font-size:13px;font-weight:700;display:none}
    .msg.ok{background:rgba(107,203,119,.12);border:1.5px solid rgba(107,203,119,.3);color:#6BCB77}
    .msg.err{background:rgba(255,107,107,.12);border:1.5px solid rgba(255,107,107,.3);color:#FF6B6B}
    .stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px}
    .stat{background:#0D0E1A;border-radius:11px;padding:14px;text-align:center}
    .stat-val{font-size:22px;font-weight:900;color:#fff;margin-bottom:3px}
    .stat-lbl{font-size:11px;color:rgba(255,255,255,.35);font-weight:700;text-transform:uppercase;letter-spacing:.4px}
    .dot-green{background:#6BCB77;box-shadow:0 0 6px #6BCB77;width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle}
    .dot-red{background:#FF6B6B;box-shadow:0 0 6px #FF6B6B;width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle}
    .dot-orange{background:#FFB347;box-shadow:0 0 6px #FFB347;width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle}
    .info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
    .info-row:last-child{border:none}
    .info-key{color:rgba(255,255,255,.4);font-weight:700}.info-val{color:#fff;font-weight:800}
    .warn-box{background:rgba(255,179,71,.08);border:1.5px solid rgba(255,179,71,.3);border-radius:11px;padding:12px 16px;font-size:13px;color:#FFB347;margin-top:14px}
    .ical-box{background:rgba(78,205,196,.05);border:1.5px solid rgba(78,205,196,.2);border-radius:12px;padding:14px;margin-top:16px}
    .ical-box h4{font-size:13px;font-weight:800;color:#4ECDC4;margin-bottom:8px}
    .ical-box p{font-size:12px;color:rgba(255,255,255,.4);line-height:1.6;margin-bottom:10px}
    code{background:#0D0E1A;border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:2px 7px;font-family:monospace;font-size:12px;color:#FFE66D}
    h3{font-size:15px;font-weight:800;color:rgba(255,255,255,.6);margin-bottom:14px}
    .spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:8px}
    @keyframes spin{to{transform:rotate(360deg)}}
    .i-steps{display:flex;justify-content:center;gap:12px;margin-bottom:24px}
    .i-step{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.06);border:2px solid rgba(255,255,255,.1);color:rgba(255,255,255,.25);font-size:14px;font-weight:900;display:flex;align-items:center;justify-content:center;transition:all .3s;position:relative}
    .i-step.active{border-color:#FF6B6B;background:rgba(255,107,107,.15);color:#FF6B6B}
    .i-step.done{border-color:#6BCB77;background:rgba(107,203,119,.12);color:#6BCB77}
    .i-step::after{content:'';position:absolute;left:38px;width:12px;height:2px;background:rgba(255,255,255,.08)}
    .i-step:last-child::after{display:none}
    .i-body{min-height:200px}
    .i-body .page{display:none}.i-body .page.active{display:block}
    .req-item{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#0D0E1A;border-radius:10px;margin-bottom:8px;font-size:13px}
    .req-label{color:rgba(255,255,255,.6);font-weight:700}
    .req-badge{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800}
    .req-badge.ok{background:rgba(107,203,119,.15);color:#6BCB77}
    .req-badge.fail{background:rgba(255,107,107,.15);color:#FF6B6B}
    .welcome-text{text-align:center;font-size:14px;color:rgba(255,255,255,.5);line-height:1.7;margin-bottom:18px}
    .welcome-features{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px}
    .wf-item{background:#0D0E1A;border-radius:10px;padding:12px;text-align:center}
    .wf-icon{font-size:22px;margin-bottom:4px}
    .wf-label{font-size:11px;color:rgba(255,255,255,.45);font-weight:700}
    .success-icon{text-align:center;font-size:56px;margin-bottom:8px}
    .success-actions{display:flex;gap:10px;margin-top:18px}
    .success-actions .btn{margin:0}
    .test-result{margin-top:10px;padding:10px 14px;border-radius:10px;font-size:12px;font-weight:700;display:none}
    .test-result.ok{display:block;background:rgba(107,203,119,.1);border:1px solid rgba(107,203,119,.25);color:#6BCB77}
    .test-result.fail{display:block;background:rgba(255,107,107,.1);border:1px solid rgba(255,107,107,.25);color:#FF6B6B}
    .btn-bar{display:flex;gap:10px;margin-top:18px}
    .btn-bar .btn{margin:0}
    .btn-back{background:rgba(255,255,255,.05);border:1.5px solid rgba(255,255,255,.08);color:rgba(255,255,255,.5)}
  </style>
</head>
<body>
<div class="card">
  <div class="logo">FamTask</div>
  <div class="sub">Admin-Panel &middot; Version <?= APP_VERSION ?></div>

  <?php if (!$status['installed']): ?>

  <?php
    $reqs = [];
    $reqs[] = ['label'=>'PHP '.phpversion(),'ok'=>version_compare(PHP_VERSION,'8.0','>='),'detail'=>'mind. 8.0 erforderlich'];
    $reqs[] = ['label'=>'PDO MySQL','ok'=>extension_loaded('pdo_mysql'),'detail'=>'Erweiterung pdo_mysql'];
    $okW = is_writable(__DIR__);
    if (!is_writable(__DIR__) && function_exists('posix_getpwuid')) { $u = posix_getpwuid(fileowner(__DIR__)); $okW = false; }
    $reqs[] = ['label'=>'Schreibrechte','ok'=>$okW,'detail'=>'Ordner "my/" muss beschreibbar sein'];
    $allOk = !in_array(false, array_column($reqs, 'ok'));
  ?>

  <div class="section active" id="tab-install">
    <div class="i-steps">
      <div class="i-step active" id="i-s1">1</div>
      <div class="i-step" id="i-s2">2</div>
      <div class="i-step" id="i-s3">3</div>
    </div>

    <div class="i-body">
      <div class="page active" id="i-p1">
        <h3>Willkommen bei FamTask</h3>
        <p class="welcome-text">
          FamTask ist deine spielerische Familien-Aufgaben-App mit Aufgaben, Belohnungen,
          Medienzeit-Controlling, Familienkalender, Lernspielen und Jukebox.
        </p>
        <div class="welcome-features">
          <div class="wf-item"><div class="wf-icon">&#x1F4CB;</div><div class="wf-label">Aufgaben &amp; Punkte</div></div>
          <div class="wf-item"><div class="wf-icon">&#x23F1;</div><div class="wf-label">Medienzeit</div></div>
          <div class="wf-item"><div class="wf-icon">&#x1F9D9;</div><div class="wf-label">14 Lernspiele</div></div>
          <div class="wf-item"><div class="wf-icon">&#x1F3B5;</div><div class="wf-label">Jukebox</div></div>
        </div>
        <h3>Voraussetzungen pr&uuml;fen</h3>
        <?php foreach ($reqs as $r): ?>
          <div class="req-item">
            <span class="req-label"><?= htmlspecialchars($r['label']) ?></span>
            <span class="req-badge <?= $r['ok']?'ok':'fail' ?>"><?= $r['ok']?'OK':$r['detail'] ?></span>
          </div>
        <?php endforeach; ?>
        <div class="btn-bar">
          <button class="btn btn-pri" onclick="goStep(2)" <?= $allOk?'':'disabled' ?> style="<?= $allOk?'':'opacity:.4;cursor:not-allowed' ?>">Weiter &rarr;</button>
        </div>
      </div>

      <div class="page" id="i-p2">
        <h3>Datenbank einrichten</h3>
        <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:14px">
          Bitte gib die Zugangsdaten deiner MariaDB- oder MySQL-Datenbank ein.
          Die Tabellen werden automatisch angelegt.
        </p>
        <div class="row">
          <div class="field"><label>Host</label><input id="i-host" value="localhost" placeholder="localhost"/></div>
          <div class="field"><label>Port</label><input id="i-port" value="3306" type="number" placeholder="3306"/></div>
        </div>
        <label>Datenbank-Name</label><input id="i-db" placeholder="z.B. famtask"/>
        <label>Benutzer</label><input id="i-user" placeholder="z.B. famtask_user"/>
        <label>Passwort</label><input id="i-pass" type="password" placeholder="Passwort der Datenbank"/>
        <div class="btn-bar">
          <button class="btn btn-back" onclick="goStep(1)">&larr; Zur&uuml;ck</button>
          <button class="btn btn-sec" onclick="testConnection()">Verbindung testen</button>
          <button class="btn btn-pri" onclick="doInstall()" id="install-btn">Jetzt installieren</button>
        </div>
        <div class="test-result" id="test-result"></div>
        <div class="msg" id="install-msg"></div>
      </div>

      <div class="page" id="i-p3">
        <div class="success-icon">&#x2705;</div>
        <h3 style="text-align:center;color:#6BCB77">Installation abgeschlossen!</h3>
        <p class="welcome-text">
          FamTask wurde erfolgreich eingerichtet. Die Datenbank-Tabellen wurden angelegt
          und die Konfiguration gespeichert.
        </p>
        <div class="success-actions">
          <a href="index.html" class="btn btn-sec" style="text-decoration:none;display:block;text-align:center">Zur App</a>
          <a href="?action=admin" class="btn btn-pri" style="text-decoration:none;display:block;text-align:center">Admin-Panel &ouml;ffnen</a>
        </div>
      </div>
    </div>
  </div>

  <?php else: ?>

  <a href="index.html" style="display:block;text-align:center;margin:-8px 0 16px;padding:8px 16px;background:rgba(78,205,196,.1);border:1.5px solid rgba(78,205,196,.25);border-radius:10px;color:#4ECDC4;font-size:12px;font-weight:800;text-decoration:none;">&larr; Zur&uuml;ck zur App (index.html)</a>
  <div class="tabs">
    <div class="tab <?= $tab===''||$tab==='status'?'active':'' ?>" data-tab="status">Status</div>
    <div class="tab <?= $tab==='families'?'active':'' ?>" data-tab="families">Familien</div>
    <div class="tab <?= $tab==='settings'?'active':'' ?>" data-tab="settings">Einstellungen</div>
    <div class="tab <?= $tab==='ical'?'active':'' ?>" data-tab="ical">Kalender</div>
    <div class="tab <?= $tab==='stats'?'active':'' ?>" data-tab="stats">Statistiken</div>
    <div class="tab <?= $tab==='update'?'active':'' ?>" data-tab="update">Update</div>
    <div class="tab <?= $tab==='backup'?'active':'' ?>" data-tab="backup">Backup</div>
    <div class="tab <?= $tab==='reinstall'?'active':'' ?>" data-tab="reinstall">Konfig</div>
  </div>

  <div class="section <?= $tab===''||$tab==='status'?'active':'' ?>" id="tab-status">
    <?php if (isset($status['error'])): ?>
      <div class="msg err" style="display:block">⚠️ DB-Fehler: <?= htmlspecialchars($status['error']) ?></div>
    <?php else: ?>
      <div class="stat-grid">
        <div class="stat"><div class="stat-val"><span class="dot-green"></span>Aktiv</div><div class="stat-lbl">Verbindung</div></div>
        <div class="stat"><div class="stat-val"><?= $status['data_rows'] ?></div><div class="stat-lbl">Datensätze</div></div>
        <div class="stat"><div class="stat-val"><?= htmlspecialchars($status['app_version']) ?></div><div class="stat-lbl">App-Version</div></div>
        <div class="stat"><div class="stat-val"><?= htmlspecialchars($status['db_version']) ?></div><div class="stat-lbl">DB-Version</div></div>
      </div>
      <div style="margin-top:16px">
        <div class="info-row"><span class="info-key">Host</span><span class="info-val"><?= htmlspecialchars($status['db_host']??'–') ?></span></div>
        <div class="info-row"><span class="info-key">Datenbank</span><span class="info-val"><?= htmlspecialchars($status['db_name']??'–') ?></span></div>
        <div class="info-row"><span class="info-key">Update</span><span class="info-val"><?= $status['update_available']?'<span class="dot-orange"></span>Update verfügbar':'<span class="dot-green"></span>Aktuell' ?></span></div>
      </div>
      <?php if ($status['update_available']): ?><div class="warn-box">⚠️ DB-Version (<?= $status['db_version'] ?>) ist veraltet. Bitte zum Tab <strong>Update</strong> wechseln.</div><?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="section <?= $tab==='families'?'active':'' ?>" id="tab-families">
    <h3>Familien verwalten</h3>
    <button class="btn btn-sec" onclick="loadFamilies()" style="margin-bottom:14px">🔄 Aktualisieren</button>
    <div id="fam-loading" style="display:none;text-align:center;padding:16px;color:rgba(255,255,255,.35)">⏳ Lade…</div>
    <div id="fam-list"></div>
    <hr style="border:none;border-top:1px solid rgba(255,255,255,.07);margin:18px 0">
    <h3>Neue Familie anlegen</h3>
    <label>Familienname</label>
    <input id="fam-name" placeholder="z.B. Familie Müller"/>
    <button class="btn btn-pri" onclick="createFamily()" style="margin-top:12px">✅ Familie erstellen</button>
    <div class="msg" id="fam-msg"></div>
  </div>

  <div class="section <?= $tab==='settings'?'active':'' ?>" id="tab-settings">
    <h3>Globale Einstellungen</h3>
    <label>Standard-Admin-PIN (für neue Familien)</label>
    <input id="set-default-pin" value="1234" maxlength="4" placeholder="1234"/>
    <label>Admin-PIN (für dieses Admin-Panel)</label>
    <input id="set-admin-pin" value="1234" maxlength="4" placeholder="1234"/>
    <div style="display:flex;gap:10px;margin-top:14px">
      <button class="btn btn-pri" onclick="saveSettings()" style="flex:1">💾 Speichern</button>
      <a href="?action=admin&logout=1" class="btn btn-sec" style="flex:1;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center">🚪 Abmelden</a>
    </div>
    <div class="msg" id="settings-msg"></div>
  </div>

  <!-- iCal / Google Calendar Tab (NEU) -->
  <div class="section <?= $tab==='ical'?'active':'' ?>" id="tab-ical">
    <h3>Google Kalender einbinden</h3>
    <div class="ical-box">
      <h4>📋 So geht's</h4>
      <p>1. In Google Kalender → Zahnrad → Einstellungen → deinen Kalender wählen<br>
         2. Ganz unten: <strong>«Öffentliche Adresse im iCal-Format»</strong> kopieren<br>
         3. URL hier einfügen und Importieren klicken</p>
      <p>💡 Für dauerhaften Import: URL in <code>.famtask_cfg.php</code> als<br>
         <code>'ical_urls' => ['Name' => 'https://...']</code> eintragen.</p>
    </div>
    <label>iCal-URL</label>
    <input id="ical-url" placeholder="https://calendar.google.com/calendar/ical/..."/>
    <button class="btn btn-pri" onclick="doIcal()">📅 Termine importieren</button>
    <div class="msg" id="ical-msg"></div>
    <div id="ical-events" style="margin-top:14px"></div>
  </div>

  <div class="section <?= $tab==='stats'?'active':'' ?>" id="tab-stats">
    <h3>Zeitraum</h3>
    <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap">
      <?php foreach([7,14,30,60,90] as $d): ?>
        <button onclick="loadStats(<?=$d?>)" id="days-btn-<?=$d?>" class="btn btn-sec" style="flex:1;padding:8px 4px;font-size:12px"><?=$d?> Tage</button>
      <?php endforeach; ?>
    </div>
    <div id="stats-loading" style="text-align:center;padding:20px;color:rgba(255,255,255,.35);display:none">⏳ Lade…</div>
    <div id="stats-content"></div>
  </div>

  <div class="section <?= $tab==='update'?'active':'' ?>" id="tab-update">
    <h3>Datenbank-Update</h3>
    <div class="info-row"><span class="info-key">App-Version</span><span class="info-val"><?= APP_VERSION ?></span></div>
    <div class="info-row"><span class="info-key">DB-Version</span><span class="info-val"><?= htmlspecialchars($status['db_version']??'–') ?></span></div>
    <?php if (!$status['update_available']): ?><div class="msg ok" style="display:block;margin-top:14px">✅ Datenbank ist aktuell.</div><?php else: ?><div class="warn-box" style="margin-bottom:0">Update von <strong><?= $status['db_version'] ?></strong> auf <strong><?= APP_VERSION ?></strong> verfügbar.</div><?php endif; ?>
    <button class="btn btn-sec" onclick="doUpdate()">🔄 Update ausführen</button>
    <div class="msg" id="update-msg"></div>
  </div>

  <div class="section <?= $tab==='reinstall'?'active':'' ?>" id="tab-reinstall">
    <h3>Datenbankverbindung ändern</h3>
    <p style="font-size:13px;color:rgba(255,255,255,.4);margin-bottom:4px">Bestehende Daten bleiben erhalten.</p>
    <div class="row"><div class="field"><label>Host</label><input id="r-host" value="<?= htmlspecialchars($status['db_host']??'localhost') ?>"/></div><div class="field"><label>Port</label><input id="r-port" value="3306" type="number"/></div></div>
    <label>Datenbank</label><input id="r-db" value="<?= htmlspecialchars($status['db_name']??'') ?>"/>
    <label>Benutzer</label><input id="r-user" placeholder="Benutzer"/>
    <label>Passwort</label><input id="r-pass" type="password" placeholder="Neues Passwort"/>
    <button class="btn btn-pri" onclick="doReinstall()">💾 Verbindung speichern</button>
    <div class="msg" id="reinstall-msg"></div>
  </div>

  <div class="section <?= $tab==='backup'?'active':'' ?>" id="tab-backup">
    <h3>Backup & Restore</h3>
    <div class="warn-box" style="margin-top:0">
      Dieses Backup enthält Familien- und Aufgabendaten. Nutze es nur lokal und sichere sensible Dateien.
    </div>

    <label>Scope</label>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:rgba(255,255,255,.65);font-weight:800">
        <input type="radio" name="backup-scope" value="all" checked/>
        Alle Familien
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:rgba(255,255,255,.65);font-weight:800">
        <input type="radio" name="backup-scope" value="family"/>
        Nur eine Familie
      </label>
    </div>
    <label style="margin-top:10px">Familiencode (nur bei “Nur eine Familie”)</label>
    <input id="backup-fc" placeholder="z.B. FAM001" value="FAM001"/>

    <button class="btn btn-sec" onclick="downloadBackup(event)" style="margin-top:14px">⬇️ Backup herunterladen</button>
    <div class="msg" id="backup-msg-download" style="margin-top:14px"></div>

    <hr style="border:none;border-top:1px solid rgba(255,255,255,.07);margin:18px 0">

    <label>Backup-Datei auswählen (JSON)</label>
    <input id="backup-file" type="file" accept="application/json"/>
    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:rgba(255,255,255,.65);font-weight:800;margin-top:10px">
      <input type="checkbox" id="backup-clear-meta"/>
      Meta beim Restore löschen
    </label>
    <button class="btn btn-pri" onclick="restoreBackup(event)" style="margin-top:14px">⏫ Backup wiederherstellen</button>
    <div class="msg" id="backup-msg" style="margin-top:14px"></div>
  </div>
  <?php endif; ?>
</div>

<script>
const ST=<?= $sj ?>;
function showTab(name){document.querySelectorAll('.tab[data-tab]').forEach(t=>t.classList.toggle('active',t.dataset.tab===name));document.querySelectorAll('.section').forEach(s=>s.classList.remove('active'));const el=document.getElementById('tab-'+name);if(el)el.classList.add('active');if(name==='stats'&&!document.getElementById('stats-content').innerHTML.trim())loadStats(30);if(name==='families')loadFamilies();if(name==='settings')loadSettings();}
document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('.tab[data-tab]').forEach(tab=>tab.addEventListener('click',()=>showTab(tab.dataset.tab)));<?php if($tab==='stats'):?>loadStats(30);<?php endif;?><?php if($tab==='families'):?>loadFamilies();<?php endif;?><?php if($tab==='settings'):?>loadSettings();<?php endif;?>});
function showMsg(id,ok,text){const el=document.getElementById(id);el.className='msg '+(ok?'ok':'err');el.textContent=text;el.style.display='block';}
function setLoading(btn,loading){if(loading){btn._orig=btn.innerHTML;btn.innerHTML='<span class="spinner"></span>Bitte warten\u2026';btn.disabled=true;}else{btn.innerHTML=btn._orig;btn.disabled=false;}}
function goStep(n){for(let i=1;i<=3;i++){document.getElementById('i-p'+i).classList.toggle('active',i===n);document.getElementById('i-s'+i).classList.toggle('active',i===n);document.getElementById('i-s'+i).classList.toggle('done',i<n);}}
async function testConnection(){const btn=event.currentTarget;const el=document.getElementById('test-result');const d={host:document.getElementById('i-host').value,port:parseInt(document.getElementById('i-port').value)||3306,db:document.getElementById('i-db').value,user:document.getElementById('i-user').value,pass:document.getElementById('i-pass').value};setLoading(btn,true);el.className='test-result';el.textContent='';try{const r=await fetch('api.php?action=testdb',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const res=await r.json();el.className='test-result '+(res.ok?'ok':'fail');el.textContent=res.ok?'Verbindung erfolgreich':res.msg;if(res.ok)document.getElementById('install-btn').classList.remove('btn-disabled');}catch(e){el.className='test-result fail';el.textContent='Netzwerkfehler: '+e.message;}setLoading(btn,false);}
async function doInstall(){const btn=document.getElementById('install-btn');const d={host:document.getElementById('i-host').value,port:parseInt(document.getElementById('i-port').value)||3306,db:document.getElementById('i-db').value,user:document.getElementById('i-user').value,pass:document.getElementById('i-pass').value};setLoading(btn,true);try{const r=await fetch('api.php?action=install',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const res=await r.json();if(res.ok){goStep(3);}else{showMsg('install-msg',false,res.msg);}}catch(e){showMsg('install-msg',false,'Netzwerkfehler: '+e.message);}setLoading(btn,false);}
async function doUpdate(){const btn=event.currentTarget;setLoading(btn,true);try{const r=await fetch('api.php?action=update',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});const d=await r.json();showMsg('update-msg',d.ok,d.msg);if(d.ok)setTimeout(()=>location.reload(),2000);}catch(e){showMsg('update-msg',false,'Netzwerkfehler: '+e.message);}setLoading(btn,false);}
async function doReinstall(){const btn=event.currentTarget;const d={host:document.getElementById('r-host').value,port:parseInt(document.getElementById('r-port').value)||3306,db:document.getElementById('r-db').value,user:document.getElementById('r-user').value,pass:document.getElementById('r-pass').value};setLoading(btn,true);try{const r=await fetch('api.php?action=install',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});const res=await r.json();showMsg('reinstall-msg',res.ok,res.msg);if(res.ok)setTimeout(()=>location.reload(),1500);}catch(e){showMsg('reinstall-msg',false,'Netzwerkfehler: '+e.message);}setLoading(btn,false);}

async function downloadBackup(e){const btn=e.currentTarget;const scope=(document.querySelector('input[name=\"backup-scope\"]:checked')?.value)||'all';const fc=(document.getElementById('backup-fc')?.value||'').trim();const msgId='backup-msg-download';setLoading(btn,true);try{let url='api.php?action=backup&scope='+encodeURIComponent(scope);if(scope==='family'){if(!fc){showMsg(msgId,false,'Bitte Familiencode angeben');setLoading(btn,false);return;}url+='&fc='+encodeURIComponent(fc);}const r=await fetch(url,{headers:{'Accept':'application/json'}});if(!r.ok){let t='';try{t=await r.text();}catch(e){}showMsg(msgId,false,'Backup fehlgeschlagen: '+t);setLoading(btn,false);return;}const blob=await r.blob();let filename='famtask-backup.json';const cd=r.headers.get('Content-Disposition');if(cd&&cd.includes('filename=')){filename=cd.split('filename=')[1].trim();filename=filename.replace(/^\"|\"$/g,'');}const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=filename;document.body.appendChild(a);a.click();a.remove();showMsg(msgId,true,'Backup heruntergeladen.');}catch(e){showMsg(msgId,false,'Fehler: '+e.message);}setLoading(btn,false);}

async function restoreBackup(e){const btn=e.currentTarget;const file=document.getElementById('backup-file').files[0];if(!file){showMsg('backup-msg',false,'Bitte Backup-Datei wählen');return;}setLoading(btn,true);try{const fd=new FormData();fd.append('backup',file);const clearMeta=document.getElementById('backup-clear-meta')?.checked;if(clearMeta)fd.append('clear_meta','1');const r=await fetch('api.php?action=restore',{method:'POST',body:fd});let d=null;try{d=await r.json();}catch(e){}if(!d){showMsg('backup-msg',false,'Restore fehlgeschlagen (keine JSON-Antwort).');setLoading(btn,false);return;}showMsg('backup-msg',d.ok,d.msg||'');if(d.ok)setTimeout(()=>location.reload(),1500);}catch(e){showMsg('backup-msg',false,'Fehler: '+e.message);}setLoading(btn,false);}

// Familien
async function loadFamilies(){document.getElementById('fam-loading').style.display='block';document.getElementById('fam-list').innerHTML='';try{const r=await fetch('api.php?action=families',{headers:{'Accept':'application/json'}});const d=await r.json();document.getElementById('fam-loading').style.display='none';if(!d.ok||!d.families.length){document.getElementById('fam-list').innerHTML='<p style="color:rgba(255,255,255,.3);font-size:13px">Noch keine Familien.</p>';return;}document.getElementById('fam-list').innerHTML=d.families.map(f=>`<div id="fam-card-${f.code}" style="background:#0D0E1A;border-radius:12px;padding:12px 14px;margin-bottom:8px"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px"><div style="flex:1"><div style="font-weight:800;color:#fff;font-size:13px">${f.name||'Unbenannt'}</div><div style="font-size:11px;color:rgba(255,255,255,.35);margin-top:2px">${f.data_rows} Datensätze · seit ${new Date(f.created_at).toLocaleDateString('de-CH')}</div></div><code style="font-size:15px;letter-spacing:2px">${f.code}</code></div><div style="display:flex;gap:6px;margin-top:8px"><input id="fam-ren-${f.code}" class="fam-ren-input" style="display:none;flex:1;background:#0D0E1A;border:1.5px solid rgba(255,255,255,.1);border-radius:8px;color:#fff;font-size:12px;padding:6px 10px;outline:none" value="${f.name||''}" placeholder="Neuer Name"/><button class="fam-ren-btn" id="fam-ren-btn-${f.code}" onclick="renameFamily('${f.code}')" style="padding:6px 12px;background:rgba(78,205,196,.12);border:1.5px solid rgba(78,205,196,.3);border-radius:8px;color:#4ECDC4;font-size:11px;font-weight:800;cursor:pointer">✏️ Umbenennen</button><button onclick="deleteFamily('${f.code}')" style="padding:6px 12px;background:rgba(255,107,107,.12);border:1.5px solid rgba(255,107,107,.3);border-radius:8px;color:#FF6B6B;font-size:11px;font-weight:800;cursor:pointer">🗑️ Löschen</button></div></div>`).join('');}catch(e){document.getElementById('fam-loading').style.display='none';}}
async function renameFamily(code){const btn=document.getElementById('fam-ren-btn-'+code);const inp=document.getElementById('fam-ren-'+code);if(inp.style.display==='block'){const name=inp.value.trim();if(!name)return;btn.innerHTML='<span class="spinner"></span>';btn.disabled=true;try{const r=await fetch('api.php?action=rename_family',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({fc:code,name})});const d=await r.json();if(d.ok){loadFamilies();showMsg('fam-msg',true,'✅ Umbenannt in "'+name+'"');}else showMsg('fam-msg',false,d.msg||'Fehler');}catch(e){showMsg('fam-msg',false,e.message);}btn.innerHTML='✏️ Umbenennen';btn.disabled=false;}else{inp.style.display='block';btn.textContent='💾 Speichern';}}
async function deleteFamily(code){if(!confirm('⚠️ Familie '+code+' wirklich löschen?\nAlle Daten werden unwiderruflich gelöscht!'))return;try{const r=await fetch('api.php?action=delete_family',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({fc:code})});const d=await r.json();if(d.ok){document.getElementById('fam-card-'+code)?.remove();showMsg('fam-msg',true,'✅ Familie '+code+' gelöscht');}else showMsg('fam-msg',false,d.msg||'Fehler');}catch(e){showMsg('fam-msg',false,e.message);}}
async function createFamily(){const name=document.getElementById('fam-name').value.trim()||'Meine Familie';try{const r=await fetch('api.php?action=register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name})});const d=await r.json();if(d.ok){showMsg('fam-msg',true,'✅ Familie "'+d.name+'" erstellt! Code: '+d.code);document.getElementById('fam-name').value='';loadFamilies();}else showMsg('fam-msg',false,d.msg||'Fehler');}catch(e){showMsg('fam-msg',false,e.message);}}

// Einstellungen
async function loadSettings(){try{const r=await fetch('api.php?action=settings',{headers:{'Accept':'application/json'}});const d=await r.json();if(d.ok&&d.settings){if(d.settings.default_pin)document.getElementById('set-default-pin').value=d.settings.default_pin;if(d.settings.admin_pin)document.getElementById('set-admin-pin').value=d.settings.admin_pin;}}catch(e){}}
async function saveSettings(){const btn=event.currentTarget;const defaultPin=document.getElementById('set-default-pin').value.trim();const adminPin=document.getElementById('set-admin-pin').value.trim();if(!/^\d{4}$/.test(defaultPin)){showMsg('settings-msg',false,'Standard-PIN muss 4 Ziffern sein');return;}if(!/^\d{4}$/.test(adminPin)){showMsg('settings-msg',false,'Admin-PIN muss 4 Ziffern sein');return;}setLoading(btn,true);try{const r=await fetch('api.php?action=save_settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({default_pin:defaultPin,admin_pin:adminPin})});const d=await r.json();showMsg('settings-msg',d.ok,d.ok?'✅ Einstellungen gespeichert – neuer Admin-PIN aktiv ab nächster Anmeldung':(d.msg||'Fehler'));}catch(e){showMsg('settings-msg',false,'Fehler: '+e.message);}setLoading(btn,false);}

// iCal Import
async function doIcal(){const btn=event.currentTarget;const url=document.getElementById('ical-url').value.trim();if(!url){showMsg('ical-msg',false,'Bitte URL eingeben');return;}setLoading(btn,true);try{const r=await fetch('api.php?action=ical',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url})});const d=await r.json();if(d.ok){showMsg('ical-msg',true,'✅ '+d.imported+' Termine importiert. '+d.count+' Termine total aktiv.'+(d.errors.length?' Fehler: '+d.errors.join(', '):''));if(d.events.length){document.getElementById('ical-events').innerHTML='<div style="margin-top:8px"><strong style="font-size:12px;color:rgba(255,255,255,.5)">IMPORTIERTE TERMINE (nächste 90 Tage)</strong></div>'+d.events.slice(0,20).map(e=>`<div style="background:#0D0E1A;border-radius:10px;padding:9px 12px;margin-top:6px;font-size:12px"><strong style="color:#FFE66D">${e.title||'Termin'}</strong> <span style="color:rgba(255,255,255,.4)">${e.date_start}${e.time_start?' · '+e.time_start:''}${e.date_end&&e.date_end!==e.date_start?' – '+e.date_end:''}</span></div>`).join('');}}else showMsg('ical-msg',false,d.msg||'Fehler');}catch(e){showMsg('ical-msg',false,'Fehler: '+e.message);}setLoading(btn,false);}

// Statistiken
async function loadStats(days){document.querySelectorAll('[id^=days-btn-]').forEach(b=>b.style.cssText='');const a=document.getElementById('days-btn-'+days);if(a)a.style.cssText='background:rgba(255,107,107,.2);border-color:rgba(255,107,107,.5);color:#FF6B6B';document.getElementById('stats-loading').style.display='block';document.getElementById('stats-content').innerHTML='';try{const r=await fetch('api.php?action=stats&days='+days,{headers:{'Accept':'application/json'}});const d=await r.json();document.getElementById('stats-loading').style.display='none';if(!d.ok){document.getElementById('stats-content').innerHTML='<p style="color:#FF6B6B">Fehler beim Laden</p>';return;}renderStats(d);}catch(e){document.getElementById('stats-loading').style.display='none';document.getElementById('stats-content').innerHTML='<p style="color:#FF6B6B">'+e.message+'</p>';}}
function bar(pct,color){return`<div style="background:rgba(255,255,255,.06);border-radius:99px;height:6px;margin-top:4px;overflow:hidden"><div style="width:${Math.round(pct)}%;background:${color};height:100%;border-radius:99px;transition:width .4s"></div></div>`;}
function renderStats(d){const el=document.getElementById('stats-content');if(!d.totals.length){el.innerHTML='<div class="msg ok" style="display:block">Noch keine Daten.</div>';return;}const colors=['#FF6B6B','#4ECDC4','#A78BFA','#FFB347','#6BCB77'];const cc={};d.totals.forEach((t,i)=>cc[t.child_name]=colors[i%colors.length]);let html='';html+='<h3 style="margin-bottom:10px">🏆 Gesamtübersicht</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px">';d.totals.forEach(t=>{const col=cc[t.child_name],total=parseInt(t.tasks_done)+parseInt(t.tasks_missed),pct=total?Math.round(parseInt(t.tasks_done)/total*100):0;html+=`<div style="background:#0D0E1A;border:1.5px solid ${col}33;border-radius:12px;padding:12px"><div style="font-weight:800;color:${col};font-size:13px;margin-bottom:8px">${t.child_name}</div><div style="display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,.5);margin-bottom:2px"><span>✅ Erledigt</span><span style="color:#6BCB77;font-weight:800">${t.tasks_done}</span></div><div style="display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,.5);margin-bottom:2px"><span>❌ Verpasst</span><span style="color:#FF6B6B;font-weight:800">${t.tasks_missed}</span></div><div style="display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,.5);margin-bottom:4px"><span>🎮 Medien</span><span style="color:#A78BFA;font-weight:800">${t.media_mins} Min</span></div>${bar(pct,'#6BCB77')}<div style="font-size:10px;color:rgba(255,255,255,.3);text-align:right;margin-top:3px">${pct}% pünktlich</div></div>`;});html+='</div>';if(d.top_tasks.length){html+='<h3 style="margin-bottom:10px">📋 Aufgaben-Ranking</h3><div style="margin-bottom:18px">';const mx=Math.max(...d.top_tasks.map(t=>parseInt(t.done)||0),1);d.top_tasks.forEach(t=>{const col=cc[t.child_name],done=parseInt(t.done)||0,missed=parseInt(t.missed)||0;html+=`<div style="background:#0D0E1A;border-radius:10px;padding:10px 12px;margin-bottom:6px"><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;font-weight:800;color:#fff">${t.ref_label||'?'}</span><span style="font-size:11px;color:${col};font-weight:800">${t.child_name}</span></div><div style="display:flex;gap:12px;font-size:11px;color:rgba(255,255,255,.45);margin-bottom:4px"><span>✅ ${done}×</span><span>❌ ${missed}×</span></div>${bar(done/mx*100,col)}</div>`;});html+='</div>';}el.innerHTML=html;}
</script>
</body>
</html>
<?php
}
