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
                        $songs[] = [
                            'title' => pathinfo($file, PATHINFO_FILENAME),
                            'src' => 'jukebox.php?action=audio&album=' . urlencode($entry) . '&file=' . urlencode($file),
                            'file' => $file,
                            'album' => $entry
                        ];
                    }
                }

                if (!empty($songs)) {
                    $albums[] = [
                        'album' => $entry,
                        'songs' => $songs
                    ];
                }
            }
            // Datei = Single
            elseif (preg_match('/\.(mp3|wav|ogg|m4a)$/i', $entry)) {
                $singles[] = [
                    'title' => pathinfo($entry, PATHINFO_FILENAME),
                    'src' => 'jukebox.php?action=audio&file=' . urlencode($entry),
                    'file' => $entry,
                    'album' => ''
                ];
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
    $term = $_GET['term'] ?? '';

    if (empty($term)) {
        echo json_encode(['coverUrl' => null]);
        exit;
    }

    // Versuche Cover aus mehreren Quellen
    $coverUrl = getCoverForTerm($term);

    echo json_encode(['coverUrl' => $coverUrl]);
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
    header('Content-Type: image/jpeg');
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
// ─────────────────────────────────────────────────────────────────
function getCoverForTerm($term) {
    $encoded = urlencode($term);

    // 1. iTunes / Apple Music API (public, kein Token nötig)
    $url = getCoverFromItunes($encoded);
    if ($url) return $url;

    // 2. Deezer API (public)
    $url = getCoverFromDeezer($encoded);
    if ($url) return $url;

    // 3. YouTube Thumbnail via Suche
    $url = getCoverFromYoutube($encoded);
    if ($url) return $url;

    // 4. Bing-Bildersuche als Fallback
    return getCoverFromBing($encoded);
}

function getCoverFromItunes($encoded) {
    try {
        $json = jbHttpGet('https://itunes.apple.com/search?term=' . $encoded . '&limit=5&entity=song', 4);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (!empty($data['results'][0]['artworkUrl100'])) {
            // 100x100 → 600x600 für höhere Auflösung
            return str_replace('/100x100bb.jpg', '/600x600bb.jpg', $data['results'][0]['artworkUrl100']);
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getCoverFromDeezer($encoded) {
    try {
        $json = jbHttpGet('https://api.deezer.com/search?q=' . $encoded . '&limit=3', 4);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (!empty($data['data'][0]['album']['cover_big'])) {
            return $data['data'][0]['album']['cover_big'];
        }
        if (!empty($data['data'][0]['album']['cover_medium'])) {
            return $data['data'][0]['album']['cover_medium'];
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getCoverFromYoutube($encoded) {
    try {
        $html = jbHttpGet('https://www.youtube.com/results?search_query=' . $encoded . '+audio', 4);
        if (!$html) return null;
        // Erste Video-ID extrahieren
        if (preg_match('/"videoId":"([a-zA-Z0-9_-]{11})"/', $html, $m)) {
            return 'https://img.youtube.com/vi/' . $m[1] . '/maxresdefault.jpg';
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getCoverFromBing($encoded) {
    try {
        $html = jbHttpGet('https://www.bing.com/images/search?q=' . $encoded . '+album+cover&form=HDRSC2', 4);
        if (!$html) return null;
        if (preg_match('/"murl":"([^"]+\.(jpg|png|webp))"/', $html, $matches)) {
            return $matches[1];
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
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
