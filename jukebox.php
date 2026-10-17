<?php
/**
 * FamTask Jukebox API – jukebox.php
 * Verwaltet Musikdateien und Cover-Kunstsuche
 */

// ── PHP-Bootstrap: keine HTML-Fehler ins JSON, Timezone nur falls ungesetzt ──
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (!ini_get('date.timezone')) { @date_default_timezone_set('UTC'); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Musik-Metadaten (ID3/MP4) – Titel, Interpret, Album, eingebettete Bilder.
// Fehlt die Datei, laeuft alles weiter wie bisher (Dateiname und Ordnername).
if (is_file(__DIR__ . '/id3.php')) { require_once __DIR__ . '/id3.php'; }

$action = $_GET['action'] ?? 'tracks';

// ─────────────────────────────────────────────────────────────────
// Scan Audio-Verzeichnis für Alben und Songs
// ─────────────────────────────────────────────────────────────────
if ($action === 'tracks') {
    $audioDir = __DIR__ . '/audio';
    $albums = [];
    $singles = [];

    if (is_dir($audioDir)) {
        $entries = array_diff(scandir($audioDir), ['.', '..']);

        foreach ($entries as $entry) {
            $fullPath = $audioDir . '/' . $entry;

            // Ordner = Album
            if (is_dir($fullPath)) {
                $files = array_diff(scandir($fullPath), ['.', '..']);
                $songs = [];

                foreach ($files as $file) {
                    if (preg_match('/\.(mp3|wav|ogg|m4a)$/i', $file)) {
                        $songs[] = jbSongEntry($fullPath . '/' . $file, $file, $entry, false);
                    }
                }

                if (!empty($songs)) {
                    // Ein eingebettetes Bild wird zum Album-Cover, sofern noch
                    // keins hinterlegt ist. Danach liefert 'coverfile' ein
                    // einziges, cachebares Bild fuer das ganze Album.
                    jbAdoptEmbeddedCover($entry, $songs);
                    $albums[] = [
                        'album' => $entry,
                        'songs' => $songs
                    ];
                }
            }
            // Datei = Single
            elseif (preg_match('/\.(mp3|wav|ogg|m4a)$/i', $entry)) {
                $singles[] = jbSongEntry($fullPath, $entry, '', true);
            }
        }
    }

    if (!empty($singles)) {
        array_unshift($albums, [
            'album' => 'Singles',
            'songs' => $singles
        ]);
    }

    // Lokale Covers prüfen
    $coverDir = __DIR__ . '/audio/.covers';
    foreach ($albums as &$album) {
        $coverFile = $coverDir . '/' . sanitizeCoverName($album['album']) . '.jpg';
        if (file_exists($coverFile)) {
            $album['localCover'] = 'jukebox.php?action=coverfile&album=' . urlencode($album['album']);
        }
    }
    unset($album);

    // Metadaten-Cache einmal schreiben (nur wenn neue Dateien gelesen wurden)
    if (function_exists('ftTagCacheFlush')) { ftTagCacheFlush(); }

    echo json_encode([
        'albums' => $albums,
        'updated' => date('c')
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Audio-Datei streamen
// ─────────────────────────────────────────────────────────────────
if ($action === 'audio') {
    $album = $_GET['album'] ?? null;
    $file = $_GET['file'] ?? null;

    // Validierung: Dateiname darf nicht leer sein
    if (!$file) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file']);
        exit;
    }

    // Validierung: Nur Dateien, keine Pfad-Traversal
    // Unicode-Zeichen sind erlaubt, aber / \ null-Bytes nicht
    if (
        strpos($file, '/') !== false ||
        strpos($file, '\\') !== false ||
        strpos($file, "\0") !== false ||
        strpos($file, '..') !== false
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file']);
        exit;
    }

    // Validierung: Album-Namen
    if ($album) {
        if (
            strpos($album, '/') !== false ||
            strpos($album, '\\') !== false ||
            strpos($album, "\0") !== false ||
            strpos($album, '..') !== false
        ) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid album']);
            exit;
        }
    }

    $audioDir = __DIR__ . '/audio';
    $filePath = $album ? $audioDir . '/' . $album . '/' . $file : $audioDir . '/' . $file;
    
    // Resolve Pfad und überprüfe Sicherheit mit realpath
    $filePath = realpath($filePath);

    // Sicherheitsprüfung: Datei muss im audio-Verzeichnis sein
    if (
        !$filePath ||
        strpos($filePath, realpath($audioDir)) !== 0 ||
        !file_exists($filePath)
    ) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        exit;
    }

    // MIME-Type bestimmen
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4'
    ];
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';

    // HTTP-Header für Streaming mit Range-Support
    header('Content-Type: ' . $mimeType);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . filesize($filePath));

    // Range-Request Support
    if (isset($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = intval($matches[1]);
            $end = $matches[2] === '' ? filesize($filePath) - 1 : intval($matches[2]);

            if ($start <= $end && $end < filesize($filePath)) {
                header('HTTP/1.1 206 Partial Content');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . filesize($filePath));
                header('Content-Length: ' . ($end - $start + 1));

                $fp = fopen($filePath, 'rb');
                fseek($fp, $start);
                echo fread($fp, $end - $start + 1);
                fclose($fp);
                exit;
            }
        }
    }

    // Normale Dateiausgabe
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    readfile($filePath);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Cover-Kunstsuche
// ─────────────────────────────────────────────────────────────────
if ($action === 'cover') {
    $album    = trim((string)($_GET['album'] ?? ''));
    $albumTag = trim((string)($_GET['albumTag'] ?? ''));
    $title    = trim((string)($_GET['title'] ?? ''));
    $artist   = trim((string)($_GET['artist'] ?? ''));
    $term     = trim((string)($_GET['term'] ?? ''));

    // Aeltere Aufrufe uebergeben nur "term" – das wird wie ein Titel behandelt.
    if ($term !== '' && $album === '' && $title === '') { $title = $term; }

    if ($album === '' && $title === '') {
        echo json_encode(['coverUrl' => null]);
        exit;
    }

    echo json_encode(['coverUrl' => getCoverForInput($album, $albumTag, $title, $artist)]);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Dateien hochladen (MP3, WAV, OGG, M4A)
// ─────────────────────────────────────────────────────────────────
if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Keine Datei empfangen']);
        exit;
    }

    $file = $_FILES['file'];

    // Upload-Fehler prüfen
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Datei überschreitet Server-Limit',
            UPLOAD_ERR_FORM_SIZE => 'Datei überschreitet Formular-Limit',
            UPLOAD_ERR_PARTIAL => 'Nur teilweise hochgeladen',
            UPLOAD_ERR_NO_FILE => 'Keine Datei ausgewählt',
            UPLOAD_ERR_NO_TMP_DIR => 'Server-Konfigurationsfehler',
            UPLOAD_ERR_CANT_WRITE => 'Server-Fehler beim Schreiben',
        ];
        $msg = $errors[$file['error']] ?? 'Unbekannter Fehler';
        http_response_code(400);
        echo json_encode(['error' => $msg]);
        exit;
    }

    // Erlaubte Formate
    $allowedExts = ['mp3', 'wav', 'ogg', 'm4a'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Format. Erlaubt: ' . implode(', ', $allowedExts)]);
        exit;
    }

    // Maximal 50 MB
    $maxSize = 50 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        http_response_code(413);
        echo json_encode(['error' => 'Datei zu gross (max 50 MB)']);
        exit;
    }

    // Audio-Verzeichnis sicherstellen
    $audioDir = __DIR__ . '/audio';
    if (!is_dir($audioDir)) {
        mkdir($audioDir, 0755, true);
    }

    // Album-Unterordner auswerten
    $album = trim($_POST['album'] ?? '');
    if ($album !== '' && $album !== 'Singles') {
        $album = preg_replace('/[^\w\-\.\x80-\xFF\s]/u', '_', $album);
        $album = trim($album);
    }
    if ($album === '' || $album === 'Singles') {
        $targetDir = $audioDir;
        $album = '';
    } else {
        $targetDir = $audioDir . '/' . $album;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
    }

    // Sicheren Dateinamen generieren
    $safeName = preg_replace('/[^\w\-\.\x80-\xFF]/u', '_', $file['name']);
    $destPath = $targetDir . '/' . $safeName;

    // Doppelte Namen vermeiden
    $counter = 1;
    $origName = $safeName;
    while (file_exists($destPath)) {
        $info = pathinfo($origName);
        $safeName = $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? $ext);
        $destPath = $targetDir . '/' . $safeName;
        $counter++;
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht gespeichert werden']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'file' => $safeName,
        'album' => $album,
        'size' => $file['size'],
        'msg' => 'Datei erfolgreich hochgeladen'
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Datei löschen
// ─────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $album = $_GET['album'] ?? '';
    $file = $_GET['file'] ?? '';

    if (!$file) {
        http_response_code(400);
        echo json_encode(['error' => 'Keine Datei angegeben']);
        exit;
    }

    if (strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, "\0") !== false || strpos($file, '..') !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Dateiname']);
        exit;
    }

    $audioDir = __DIR__ . '/audio';

    if ($album && $album !== 'Singles') {
        if (strpos($album, '/') !== false || strpos($album, '\\') !== false || strpos($album, "\0") !== false || strpos($album, '..') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiger Albumname']);
            exit;
        }
        $filePath = $audioDir . '/' . $album . '/' . $file;
    } else {
        $filePath = $audioDir . '/' . $file;
    }

    $filePath = realpath($filePath);
    if (!$filePath || strpos($filePath, realpath($audioDir)) !== 0 || !file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden']);
        exit;
    }

    if (!unlink($filePath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht gelöscht werden']);
        exit;
    }

    echo json_encode(['success' => true, 'msg' => 'Datei gelöscht']);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Datei umbenennen
// ─────────────────────────────────────────────────────────────────
if ($action === 'rename') {
    $album = $_GET['album'] ?? '';
    $file = $_GET['file'] ?? '';
    $newName = trim($_GET['newName'] ?? '');

    if (!$file || !$newName) {
        http_response_code(400);
        echo json_encode(['error' => 'Datei und neuer Name erforderlich']);
        exit;
    }

    if (strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, "\0") !== false || strpos($file, '..') !== false) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Dateiname']);
        exit;
    }

    // Neuen Namen säubern und Extension sicherstellen
    $newName = preg_replace('/[^\w\-\.\x80-\xFF\s]/u', '_', $newName);
    $newName = trim($newName);
    $ext = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
    $allowedExts = ['mp3', 'wav', 'ogg', 'm4a'];
    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Format. Erlaubt: ' . implode(', ', $allowedExts)]);
        exit;
    }

    $audioDir = __DIR__ . '/audio';
    $audioDirReal = realpath($audioDir);

    if ($album && $album !== 'Singles') {
        if (strpos($album, '/') !== false || strpos($album, '\\') !== false || strpos($album, "\0") !== false || strpos($album, '..') !== false) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiger Albumname']);
            exit;
        }
        $oldPath = $audioDir . '/' . $album . '/' . $file;
    } else {
        $oldPath = $audioDir . '/' . $file;
    }

    $oldPath = realpath($oldPath);
    if (!$oldPath || strpos($oldPath, $audioDirReal) !== 0 || !file_exists($oldPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Datei nicht gefunden']);
        exit;
    }

    // Neuen Pfad im selben Verzeichnis erstellen
    $newPath = dirname($oldPath) . '/' . $newName;

    if (file_exists($newPath)) {
        http_response_code(409);
        echo json_encode(['error' => 'Eine Datei mit diesem Namen existiert bereits']);
        exit;
    }

    if (!rename($oldPath, $newPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Datei konnte nicht umbenannt werden']);
        exit;
    }

    echo json_encode(['success' => true, 'msg' => 'Datei umbenannt', 'file' => $newName]);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Eingebettetes Cover aus den Metadaten ausliefern
// ─────────────────────────────────────────────────────────────────
if ($action === 'tagcover') {
    $path = jbResolveAudioPath(trim((string)($_GET['album'] ?? '')), trim((string)($_GET['file'] ?? '')));
    if (!$path) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid file']);
        exit;
    }
    $cover = function_exists('ftTagEmbeddedCover') ? ftTagEmbeddedCover($path) : null;
    if (!$cover || empty($cover['data'])) {
        http_response_code(404);
        echo json_encode(['error' => 'No cover']);
        exit;
    }
    header('Content-Type: ' . $cover['mime']);
    header('Cache-Control: max-age=604800, public');
    header('Content-Length: ' . strlen($cover['data']));
    echo $cover['data'];
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Lokales Cover-Bild ausliefern
// ─────────────────────────────────────────────────────────────────
if ($action === 'coverfile') {
    $album = $_GET['album'] ?? '';
    if (!$album) {
        http_response_code(400);
        echo json_encode(['error' => 'No album specified']);
        exit;
    }
    $coverFile = __DIR__ . '/audio/.covers/' . sanitizeCoverName($album) . '.jpg';
    if (!file_exists($coverFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'No cover']);
        exit;
    }
    // Die Datei endet immer auf .jpg, kann aber aus den Metadaten stammen und
    // dann PNG/WebP sein – Typ deshalb am Inhalt erkennen.
    $head = (string)@file_get_contents($coverFile, false, null, 0, 16);
    $mime = function_exists('ftTagSniffMime') ? ftTagSniffMime($head) : 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: max-age=86400, public');
    header('Content-Length: ' . filesize($coverFile));
    readfile($coverFile);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Manuelles Cover setzen (POST: url=… oder image-Upload)
// ─────────────────────────────────────────────────────────────────
if ($action === 'setcover') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $album = trim($_POST['album'] ?? '');
    if (!$album) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Album angegeben']);
        exit;
    }

    $coverDir = __DIR__ . '/audio/.covers';
    if (!is_dir($coverDir)) {
        mkdir($coverDir, 0755, true);
    }

    $destPath = $coverDir . '/' . sanitizeCoverName($album) . '.jpg';

    // Variante A: Bild von URL herunterladen (cURL mit fopen-Fallback)
    if (!empty($_POST['url'])) {
        $url = filter_var(trim($_POST['url']), FILTER_VALIDATE_URL);
        if (!$url) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige URL']);
            exit;
        }
        $imageData = jbHttpGet($url, 10);
        if (!$imageData) {
            http_response_code(400);
            echo json_encode(['error' => 'Bild konnte nicht von der URL geladen werden']);
            exit;
        }
        if (!@file_put_contents($destPath, $imageData)) {
            http_response_code(500);
            echo json_encode(['error' => 'Cover konnte nicht gespeichert werden']);
            exit;
        }
        echo json_encode(['success' => true, 'msg' => 'Cover von URL gespeichert']);
        exit;
    }

    // Variante B: Bild hochladen
    if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $mime = jbFileMime($_FILES['image']['tmp_name']);
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($mime, $allowedMime)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültiges Bildformat. Erlaubt: JPG, PNG, WebP, GIF']);
            exit;
        }
        if (!@move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Cover konnte nicht gespeichert werden']);
            exit;
        }
        echo json_encode(['success' => true, 'msg' => 'Cover hochgeladen']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Bitte URL oder Bilddatei angeben']);
    exit;
}

// ─────────────────────────────────────────────────────────────────
// Manuelles Cover entfernen
// ─────────────────────────────────────────────────────────────────
if ($action === 'removecover') {
    $album = trim($_GET['album'] ?? '');
    if (!$album) {
        http_response_code(400);
        echo json_encode(['error' => 'Kein Album angegeben']);
        exit;
    }
    $coverFile = __DIR__ . '/audio/.covers/' . sanitizeCoverName($album) . '.jpg';
    if (file_exists($coverFile)) {
        @unlink($coverFile);
    }
    echo json_encode(['success' => true, 'msg' => 'Cover entfernt']);
    exit;
}

// Standard-Fehler
http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
exit;

// ─────────────────────────────────────────────────────────────────
// Cover-Suche über mehrere Quellen
//
// Gesucht wird bevorzugt nach dem Album (Ordnername bzw. ID3-Album), erst
// danach nach Titel und Interpret. Jeder Treffer wird mit dem gesuchten
// Namen verglichen – lieber kein Cover als ein falsches. Die früheren
// Quellen YouTube-Thumbnail und Bing-Bildersuche sind entfernt: sie
// lieferten praktisch beliebige Bilder.
// ─────────────────────────────────────────────────────────────────

/** Sucht ein passendes Cover zu Album/Titel/Interpret. */
function getCoverForInput(string $albumFolder, string $albumTag, string $title, string $artist): ?string {
    // Erwarteter Name: Ordnername UND ID3-Album zusammen. Der Ordner liefert
    // meist die Serie ("Bibi Blocksberg 12"), das Tag die Folge ("Folge 12:
    // hat Geburtstag"). Erst zusammen ist der Vergleich eindeutig – sonst
    // passt "Folge 12" auch auf jede andere Serie.
    $erwartet  = trim($albumFolder . ' ' . (($albumTag !== '' && $albumTag !== $albumFolder) ? $albumTag : ''));
    $suchterme = [];
    if ($albumFolder !== '') $suchterme[] = $albumFolder;

    // Zweiter Versuch: Serie + ID3-Album zusammen. Ordner wie "Benjamin
    // Blümchen 3" und Tag "Folge 3" finden einzeln nichts, "Benjamin Blümchen
    // Folge 3" dagegen schon. Nur wenn der Ordner nicht selbst "Folge/Teil"
    // enthält – sonst wäre der Ordner schon der richtige Suchbegriff.
    if ($albumTag !== '' && !preg_match('/\b(folge|teil|band|vol|part|cd)\b/i', $albumFolder)) {
        $serie = trim((string)preg_replace('/(?<=\D)\s*\d+\s*$/', '', $albumFolder));
        if ($serie !== '' && $serie !== $albumFolder) {
            $kombi = trim($serie . ' ' . $albumTag);
            if (!in_array($kombi, $suchterme, true)) { $suchterme[] = $kombi; }
        }
    }

    // Zuletzt das ID3-Album allein (oft nur "Folge 12" – der Ordnername sagt
    // dann nichts und der Treffer wird ueber $erwartet geprueft).
    if ($albumTag !== '' && $albumTag !== $albumFolder) { $suchterme[] = $albumTag; }

    // 1. Album-Suche – bester Treffer bei Hörspielen und Sammlungen
    foreach ($suchterme as $term) {
        $url = getCoverFromItunesAlbum($term, $erwartet);
        if ($url) return $url;
        $url = getCoverFromDeezerAlbum($term, $erwartet);
        if ($url) return $url;
    }

    // 2. Titel-Suche (mit Interpret, sofern bekannt)
    if ($title !== '') {
        $url = getCoverFromItunesSong($title, $artist);
        if ($url) return $url;
        $url = getCoverFromDeezerTrack($title, $artist);
        if ($url) return $url;
    }

    // 3. MusicBrainz/Cover Art Archive als letzter Versuch
    return getCoverFromMusicBrainz($erwartet !== '' ? $erwartet : $title);
}

/** Vergleichsform eines Namens: klein, Umlaute aufgelöst, nur Wortzeichen. */
function coverNorm(string $s): string {
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($s, [
        'ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','à'=>'a','á'=>'a','â'=>'a','å'=>'a','ã'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ñ'=>'n','ç'=>'c',
    ]);
    $s = (string)preg_replace('/[^a-z0-9]+/u', ' ', $s);
    $s = (string)preg_replace('/\b0+(\d+)\b/', '$1', $s);   // "Folge 012" = "Folge 12"
    return trim((string)preg_replace('/\s+/', ' ', $s));
}

/**
 * Ähnlichkeit zweier Namen, 0.0 bis 1.0.
 *
 * Artikel werden bewusst NICHT entfernt: "The Album" und "Album A" wären
 * sonst identisch. Ziffern müssen übereinstimmen – bei Hörspielen ist
 * "Folge 12" etwas anderes als "Folge 127".
 */
function coverNameScore(string $want, string $have): float {
    $a = coverNorm($want);
    $b = coverNorm($have);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;                   // exakt gleich – gilt auch für kurze Namen
    $la = function_exists('mb_strlen') ? mb_strlen($a) : strlen($a);
    $lb = function_exists('mb_strlen') ? mb_strlen($b) : strlen($b);
    if ($la < 3 || $lb < 3) return 0.0;          // zu kurz für einen unscharfen Vergleich

    $ta = array_values(array_unique(explode(' ', $a)));
    $tb = array_values(array_unique(explode(' ', $b)));
    foreach ($ta as $wort) {
        if (ctype_digit($wort) && !in_array($wort, $tb, true)) return 0.0;
    }

    $inter = count(array_intersect($ta, $tb));
    if ($inter === 0) return 0.0;

    // Anteil der gesuchten Wörter, die im Fund vorkommen. Sind es (fast) alle,
    // ist es derselbe Titel mit Zusatz wie "Hörspiel" oder "Teil 1".
    $coverage = $inter / count($ta);
    if ($coverage >= 0.6) return 0.9;

    $jaccard = $inter / count(array_unique(array_merge($ta, $tb)));
    return max($jaccard, $coverage * 0.85);
}

/** 100x100-URL der iTunes-API auf 600x600 anheben. */
function itunesArtwork(string $url): string {
    $big = preg_replace('#/\d+x\d+bb\.(jpg|png)$#i', '/600x600bb.$1', $url);
    return $big ?: $url;
}

/** iTunes: Album-Suche. Verglichen wird mit dem erwarteten Namen. */
function getCoverFromItunesAlbum(string $term, string $erwartet): ?string {
    try {
        $json = jbHttpGet('https://itunes.apple.com/search?term=' . urlencode($term) . '&limit=15&entity=album', 5);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (empty($data['results']) || !is_array($data['results'])) return null;

        $best = null; $bestScore = 0.0;
        foreach ($data['results'] as $r) {
            if (empty($r['artworkUrl100'])) continue;
            // Bei Hörspielen steht der Serienname im Interpret, der Albumname
            // ist nur "Folge 12: …" – deshalb beides zusammen vergleichen.
            $name = trim((string)($r['artistName'] ?? '') . ' ' . (string)($r['collectionName'] ?? ''));
            $s = coverNameScore($erwartet, $name);
            if ($s > $bestScore) { $bestScore = $s; $best = $r; }
        }
        if (!$best || $bestScore < 0.62) return null;   // kein ausreichend ähnlicher Treffer
        return itunesArtwork((string)$best['artworkUrl100']);
    } catch (Exception $e) {
        return null;
    }
}

/** iTunes: Titel-Suche (mit Interpret, sofern angegeben). */
function getCoverFromItunesSong(string $title, string $artist): ?string {
    try {
        $term = trim(($artist !== '' ? $artist . ' ' : '') . $title);
        $json = jbHttpGet('https://itunes.apple.com/search?term=' . urlencode($term) . '&limit=15&entity=song', 5);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (empty($data['results']) || !is_array($data['results'])) return null;

        $best = null; $bestScore = 0.0;
        foreach ($data['results'] as $r) {
            if (empty($r['artworkUrl100'])) continue;
            $tScore = coverNameScore($title, (string)($r['trackName'] ?? ''));
            if ($tScore < 0.70) continue;
            if ($artist !== '' && !empty($r['artistName']) && coverNameScore($artist, (string)$r['artistName']) < 0.5) continue;
            if ($tScore > $bestScore) { $bestScore = $tScore; $best = $r; }
        }
        if (!$best) return null;
        return itunesArtwork((string)$best['artworkUrl100']);
    } catch (Exception $e) {
        return null;
    }
}

/** Deezer: Album-Suche. Verglichen wird mit dem erwarteten Namen. */
function getCoverFromDeezerAlbum(string $term, string $erwartet): ?string {
    try {
        $json = jbHttpGet('https://api.deezer.com/search/album?q=' . urlencode($term) . '&limit=15', 5);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (empty($data['data']) || !is_array($data['data'])) return null;

        $best = null; $bestScore = 0.0;
        foreach ($data['data'] as $r) {
            $name = trim((string)($r['artist']['name'] ?? '') . ' ' . (string)($r['title'] ?? ''));
            $s = coverNameScore($erwartet, $name);
            if ($s > $bestScore) { $bestScore = $s; $best = $r; }
        }
        if (!$best || $bestScore < 0.62) return null;
        return $best['cover_big'] ?? $best['cover_medium'] ?? $best['cover'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

/** Deezer: Titel-Suche (mit Interpret, sofern angegeben). */
function getCoverFromDeezerTrack(string $title, string $artist): ?string {
    try {
        $q = trim(($artist !== '' ? $artist . ' ' : '') . $title);
        $json = jbHttpGet('https://api.deezer.com/search?q=' . urlencode($q) . '&limit=15', 5);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (empty($data['data']) || !is_array($data['data'])) return null;

        $best = null; $bestScore = 0.0;
        foreach ($data['data'] as $r) {
            $tScore = coverNameScore($title, (string)($r['title'] ?? ''));
            if ($tScore < 0.70) continue;
            $rArtist = (string)($r['artist']['name'] ?? '');
            if ($artist !== '' && $rArtist !== '' && coverNameScore($artist, $rArtist) < 0.5) continue;
            if ($tScore > $bestScore) { $bestScore = $tScore; $best = $r; }
        }
        if (!$best) return null;
        $cov = $best['album']['cover_big'] ?? $best['album']['cover_medium'] ?? $best['album']['cover'] ?? null;
        return $cov ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** MusicBrainz + Cover Art Archive (letzter Versuch, wird gedrosselt). */
function getCoverFromMusicBrainz(string $query): ?string {
    try {
        $q = trim((string)preg_replace('/["\\\\:\(\)\[\]\{\}]/', ' ', $query));
        if ($q === '') return null;
        $json = jbHttpGet('https://musicbrainz.org/ws/2/release/?query=' . urlencode($q) . '&fmt=json&limit=10', 6);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (empty($data['releases']) || !is_array($data['releases'])) return null;

        $best = null; $bestScore = 0.0;
        foreach ($data['releases'] as $r) {
            $s = coverNameScore($query, (string)($r['title'] ?? ''));
            if ($s > $bestScore) { $bestScore = $s; $best = $r; }
        }
        if (!$best || $bestScore < 0.62) return null;
        if (empty($best['cover-art-archive']['front'])) return null;   // laut MusicBrainz kein Vorderseitenbild
        return 'https://coverartarchive.org/release/' . rawurlencode((string)$best['id']) . '/front-500';
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Einen Song-Eintrag für die Album-Liste bauen.
 * Titel/Interpret/Album kommen aus den Metadaten (ID3/MP4); fehlen sie,
 * greifen Dateiname und Ordnername wie bisher. 'album' bleibt bewusst der
 * Ordnername – er ist der Schlüssel für Cover und Album-Zuordnung.
 */
function jbSongEntry(string $path, string $file, string $album, bool $isSingle): array {
    $title     = (string)pathinfo($file, PATHINFO_FILENAME);
    $artist    = '';
    $albumTag  = '';
    $hasCover  = false;

    if (function_exists('ftTagRead')) {
        $tags = ftTagRead($path, ($album !== '' ? $album . '/' : '') . $file);
        if (($tags['title'] ?? '') !== '')  { $title    = (string)$tags['title']; }
        $artist   = (string)($tags['artist'] ?? '');
        $albumTag = (string)($tags['album'] ?? '');
        $hasCover = (bool)($tags['hasCover'] ?? false);
    }

    $src = 'jukebox.php?action=audio';
    if (!$isSingle) { $src .= '&album=' . urlencode($album); }
    $src .= '&file=' . urlencode($file);

    return [
        'title'    => $title,
        'artist'   => $artist,
        'albumTag' => $albumTag,
        'hasCover' => $hasCover,
        'src'      => $src,
        'file'     => $file,
        'album'    => $album,
    ];
}

/**
 * Erstes in den Metadaten vorhandenes Bild als Album-Cover übernehmen,
 * sofern noch keins existiert. Danach genügt ein Bild (und ein Ladevorgang)
 * für das ganze Album, und ein manuell gesetztes Cover bleibt unangetastet.
 */
function jbAdoptEmbeddedCover(string $album, array $songs): void {
    if ($album === '' || !function_exists('ftTagEmbeddedCover')) return;
    $coverDir  = __DIR__ . '/audio/.covers';
    $coverFile = $coverDir . '/' . sanitizeCoverName($album) . '.jpg';
    if (file_exists($coverFile)) return;

    foreach ($songs as $song) {
        if (empty($song['hasCover']) || empty($song['file'])) continue;
        $path = __DIR__ . '/audio/' . $album . '/' . $song['file'];
        if (!is_file($path)) continue;
        $img = ftTagEmbeddedCover($path);
        if (!$img || empty($img['data'])) continue;
        if (!is_dir($coverDir)) { @mkdir($coverDir, 0755, true); }
        if (@file_put_contents($coverFile, $img['data']) !== false) return;
    }
}

/** Enthält einen Namen eine unerlaubte Pfad-Sequenz? */
function jbUnsafeName(string $s): bool {
    return strpos($s, '/') !== false
        || strpos($s, '\\') !== false
        || strpos($s, "\0") !== false
        || strpos($s, '..') !== false;
}

/** Audiopfad aus Album+Datei auflösen und auf audio/ beschränken. */
function jbResolveAudioPath(string $album, string $file): ?string {
    if ($file === '' || jbUnsafeName($file)) return null;
    if ($album !== '' && jbUnsafeName($album)) return null;

    $path = __DIR__ . '/audio' . ($album !== '' ? '/' . $album : '') . '/' . $file;
    if (!is_file($path)) return null;

    // Sicherstellen, dass die Datei wirklich unter audio/ liegt
    $real = realpath($path);
    $base = realpath(__DIR__ . '/audio');
    if ($real === false || $base === false) return null;
    if (strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) return null;
    return $real;
}

function sanitizeCoverName($name) {
    $name = preg_replace('/[^\w\-\.\x80-\xFF\s]/u', '_', $name);
    $name = preg_replace('/\s+/', '_', $name);
    $name = trim($name);
    return function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
}

// HTTP-Fetch mit cURL, sonst fopen-Fallback (max. Kompatibilität mit Shared Hosting)
function jbHttpGet(string $url, int $timeout = 4): string|false {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body === false || $code >= 400) ? false : $body;
    }
    $ctx = stream_context_create(['http' => [
        'timeout'      => $timeout,
        'user_agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'ignore_errors'=> true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return ($body === false) ? false : $body;
}

// MIME-Type ermitteln – fileinfo wenn verfügbar, sonst Fallback über Datei-Ende
function jbFileMime(string $path): string {
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) { $mime = @finfo_file($finfo, $path); finfo_close($finfo); return $mime ?: 'application/octet-stream'; }
    }
    $raw = @file_get_contents($path, false, null, 0, 64);
    if ($raw === false) return 'application/octet-stream';
    if (str_starts_with($raw, "\xFF\xD8\xFF"))            return 'image/jpeg';
    if (str_starts_with($raw, "\x89PNG\r\n\x1a\n"))      return 'image/png';
    if (str_starts_with($raw, "GIF8"))                   return 'image/gif';
    if (str_starts_with($raw, "RIFF") && str_contains(substr($raw, 8, 4), "WEBP")) return 'image/webp';
    return 'application/octet-stream';
}
?>
