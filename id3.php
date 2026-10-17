<?php
/**
 * FamTask – Metadaten aus Musikdateien lesen
 *
 * Eigener, schlanker Parser – keine Fremdbibliothek. Gelesen werden:
 *   • MP3  : ID3v2.3 / ID3v2.4 (TIT2, TPE1, TALB, APIC) und ID3v1 als Fallback
 *   • M4A/MP4: iTunes-Atome im moov/udta/meta/ilst (©nam, ©ART, ©alb, covr)
 *
 * Ziel ist nicht Vollständigkeit, sondern ein robuster Treffer für die
 * Cover-Suche: Dateiname und Ordnername sind oft unbrauchbar ("01 - Titel"),
 * die Tags enthalten dagegen Titel, Interpret und Album.
 *
 * Fehlt eine Datei oder ist sie beschädigt, wird ein leeres Ergebnis geliefert;
 * der Aufrufer faellt dann auf Dateiname/Ordnername zurueck.
 */

/** 4 Byte big-endian. */
function ftTagU32(string $b): int {
    if (strlen($b) < 4) return 0;
    $u = unpack('N', $b);
    return $u ? (int)$u[1] : 0;
}

/** 4 Byte "syncsafe" (7 Bit je Byte) – ID3v2.4-Groessen. */
function ftTagSyncSafe(string $b): int {
    if (strlen($b) < 4) return 0;
    return ((ord($b[0]) & 0x7F) << 21) | ((ord($b[1]) & 0x7F) << 14)
         | ((ord($b[2]) & 0x7F) << 7)  |  (ord($b[3]) & 0x7F);
}

/** ISO-8859-1 nach UTF-8 (fuer ID3v2-Kodierung 0). */
function ftTagLatin(string $s): string {
    return (string)@mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
}

/** Erraet den Bildtyp, wenn die Quelle keinen MIME-Typ mitliefert. */
function ftTagSniffMime(string $d): string {
    if (substr($d, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
    if (substr($d, 0, 8) === "\x89PNG\r\n\x1a\n") return 'image/png';
    if (substr($d, 0, 4) === 'RIFF' && substr($d, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($d, 0, 4) === 'GIF8') return 'image/gif';
    return 'image/jpeg';
}

/** Texte in ID3-Frames: erstes Byte ist die Kodierung, danach folgt der Text. */
function ftTagFrameText(string $payload): string {
    if ($payload === '') return '';
    $enc  = ord($payload[0]);
    $body = substr($payload, 1);
    switch ($enc) {
        case 0: $s = ftTagLatin($body); break;
        case 1: // UTF-16 mit BOM
            if (substr($body, 0, 2) === "\xFF\xFE") {
                $s = (string)@mb_convert_encoding(substr($body, 2), 'UTF-8', 'UTF-16LE');
            } else {
                $s = (string)@mb_convert_encoding(substr($body, 2), 'UTF-8', 'UTF-16BE');
            }
            break;
        case 2: $s = (string)@mb_convert_encoding($body, 'UTF-8', 'UTF-16BE'); break; // ID3v2.4
        case 3: $s = $body; break;                                                    // UTF-8
        default: $s = ftTagLatin($body);
    }
    return trim(str_replace("\0", ' ', $s));
}

/** APIC-Frame (eingebettetes Bild) auswerten. */
function ftTagParseApic(string $p): ?array {
    if (strlen($p) < 4) return null;
    $enc  = ord($p[0]);
    $nul  = ($enc === 1 || $enc === 2) ? "\0\0" : "\0";
    $pos  = 1;
    // MIME-Typ
    $mimeEnd = strpos($p, $nul, $pos);
    if ($mimeEnd === false) return null;
    $mime = substr($p, $pos, $mimeEnd - $pos);
    $pos  = $mimeEnd + strlen($nul);
    // Bildtyp (1 Byte)
    if ($pos >= strlen($p)) return null;
    $pos += 1;
    // Beschreibung
    $descEnd = strpos($p, $nul, $pos);
    if ($descEnd === false) return null;
    $pos = $descEnd + strlen($nul);
    $data = substr($p, $pos);
    if ($data === '') return null;
    $mime = strtolower($mime);
    if ($mime === 'image/jpg') $mime = 'image/jpeg';
    if ($mime === '' || $mime === 'application/octet-stream') $mime = ftTagSniffMime($data);
    return ['mime' => $mime, 'data' => $data];
}

/**
 * ID3v2-Block auswerten (der Block beginnt direkt mit "ID3").
 * $withCover steuert, ob APIC-Bilddaten mitgelesen werden.
 */
function ftTagParseId3v2(string $data, bool $withCover, ?array &$cover): array {
    $out = ['title' => '', 'artist' => '', 'album' => '', 'hasCover' => false];
    if (strlen($data) < 10 || substr($data, 0, 3) !== 'ID3') return $out;

    $major = ord($data[3]);
    $flags = ord($data[5]);
    $size  = ftTagSyncSafe(substr($data, 6, 4));
    if ($major < 3 || $major > 4) return $out;   // ID3v2.2 kommt praktisch nicht mehr vor
    if ($size <= 0) return $out;

    $body = substr($data, 10, $size);
    if ($flags & 0x80) {                          // Unsynchronisation
        $body = str_replace("\xFF\x00", "\xFF", $body);
    }

    $pos = 0;
    if ($flags & 0x40) {                          // erweiterter Header
        if ($major === 3) {
            $pos = 4 + ftTagU32(substr($body, 0, 4));
        } else {
            $pos = ftTagSyncSafe(substr($body, 0, 4));
        }
    }

    $end = strlen($body);
    while ($pos + 10 <= $end) {
        $id = substr($body, $pos, 4);
        if (!preg_match('/^[A-Z0-9]{4}$/', $id)) break;
        $fsize = ($major === 4)
            ? ftTagSyncSafe(substr($body, $pos + 4, 4))
            : ftTagU32(substr($body, $pos + 4, 4));
        if ($fsize <= 0 || $pos + 10 + $fsize > $end) break;

        switch ($id) {
            case 'TIT2': $out['title']  = ftTagFrameText(substr($body, $pos + 10, $fsize)); break;
            case 'TPE1': $out['artist'] = ftTagFrameText(substr($body, $pos + 10, $fsize)); break;
            case 'TALB': $out['album']  = ftTagFrameText(substr($body, $pos + 10, $fsize)); break;
            case 'APIC':
                // Vorhandensein auch ohne Coverbedarf merken – die Bilddaten
                // selbst werden nur bei $withCover in den Speicher geholt.
                $out['hasCover'] = true;
                if ($withCover && $cover === null) {
                    $cover = ftTagParseApic(substr($body, $pos + 10, $fsize));
                }
                break;
        }
        $pos += 10 + $fsize;
    }
    return $out;
}

/** ID3v1 (letzte 128 Byte) – nur Texte, kein Bild. */
function ftTagParseId3v1(string $tail): array {
    $out = ['title' => '', 'artist' => '', 'album' => ''];
    if (strlen($tail) < 128 || substr($tail, 0, 3) !== 'TAG') return $out;
    $out['title']  = trim(ftTagLatin(substr($tail, 3, 30)));
    $out['artist'] = trim(ftTagLatin(substr($tail, 33, 30)));
    $out['album']  = trim(ftTagLatin(substr($tail, 63, 30)));
    return $out;
}

/** Naechstes Atom der angegebenen Art in [$start,$end) finden. */
function ftTagFindAtom($fh, int $start, int $end, string $type): ?array {
    $pos = $start;
    while ($pos + 8 <= $end) {
        if (fseek($fh, $pos) !== 0) return null;
        $hdr = fread($fh, 8);
        if (strlen($hdr) < 8) return null;
        $size = ftTagU32(substr($hdr, 0, 4));
        $t    = substr($hdr, 4, 4);
        $hlen = 8;
        if ($size === 1) {                       // 64-Bit-Groesse
            $ext = fread($fh, 8);
            if (strlen($ext) < 8) return null;
            $size = ftTagU32(substr($ext, 0, 4)) * 4294967296 + ftTagU32(substr($ext, 4, 4));
            $hlen = 16;
        } elseif ($size === 0) {
            $size = $end - $pos;
        }
        if ($size < $hlen || $pos + $size > $end) return null;
        if ($t === $type) return [$pos + $hlen, $pos + $size];
        $pos += $size;
    }
    return null;
}

/** Datenatom (data) innerhalb eines ilst-Eintrags lesen. */
function ftTagDataAtom($fh, int $start, int $end): ?array {
    $d = ftTagFindAtom($fh, $start, $end, 'data');
    if (!$d) return null;
    $len = $d[1] - $d[0];
    if ($len < 8) return null;
    if (fseek($fh, $d[0]) !== 0) return null;
    $head = fread($fh, 8);                       // 4 Byte Version/Flags + 4 Byte Locale
    if (strlen($head) < 8) return null;
    $wkt     = ord($head[3]);                    // 1 = UTF-8, 13 = JPEG, 14 = PNG
    $payload = ($len > 8) ? (string)fread($fh, $len - 8) : '';
    return ['wkt' => $wkt, 'data' => $payload];
}

/** M4A/MP4: iTunes-Metadaten lesen. */
function ftTagParseMp4($fh, int $fileSize, bool $withCover, ?array &$cover): array {
    $out = ['title' => '', 'artist' => '', 'album' => '', 'hasCover' => false];
    $moov = ftTagFindAtom($fh, 0, $fileSize, 'moov');
    if (!$moov) return $out;
    $udta = ftTagFindAtom($fh, $moov[0], $moov[1], 'udta');
    if (!$udta) return $out;
    $meta = ftTagFindAtom($fh, $udta[0], $udta[1], 'meta');
    if (!$meta) return $out;
    $ilst = ftTagFindAtom($fh, $meta[0] + 4, $meta[1], 'ilst');   // meta hat 4 Byte Version/Flags
    if (!$ilst) return $out;

    $pos = $ilst[0];
    while ($pos + 8 <= $ilst[1]) {
        if (fseek($fh, $pos) !== 0) break;
        $hdr = fread($fh, 8);
        if (strlen($hdr) < 8) break;
        $size = ftTagU32(substr($hdr, 0, 4));
        $type = substr($hdr, 4, 4);
        $hlen = 8;
        if ($size === 1) {
            $ext = fread($fh, 8);
            if (strlen($ext) < 8) break;
            $size = ftTagU32(substr($ext, 0, 4)) * 4294967296 + ftTagU32(substr($ext, 4, 4));
            $hlen = 16;
        } elseif ($size === 0) {
            $size = $ilst[1] - $pos;
        }
        if ($size < $hlen || $pos + $size > $ilst[1]) break;

        if ($type === 'covr') {
            // Vorhandensein auch ohne Coverbedarf merken – die Bilddaten
            // werden nur bei $withCover gelesen.
            $out['hasCover'] = true;
            if ($withCover && $cover === null) {
                $d = ftTagDataAtom($fh, $pos + $hlen, $pos + $size);
                if ($d && $d['data'] !== '') {
                    $mime = $d['wkt'] === 13 ? 'image/jpeg' : ($d['wkt'] === 14 ? 'image/png' : ftTagSniffMime($d['data']));
                    $cover = ['mime' => $mime, 'data' => $d['data']];
                }
            }
        } elseif ($type === "\xA9nam" || $type === "\xA9ART" || $type === "\xA9alb") {
            $d = ftTagDataAtom($fh, $pos + $hlen, $pos + $size);
            if ($d) {
                $txt = trim($d['data']);
                if ($type === "\xA9nam") $out['title']  = $txt;
                if ($type === "\xA9ART") $out['artist'] = $txt;
                if ($type === "\xA9alb") $out['album']  = $txt;
            }
        }
        $pos += $size;
    }
    return $out;
}

/**
 * Tags einer Datei lesen.
 *
 * @return array{title:string,artist:string,album:string,cover:?array{mime:string,data:string}}
 */
function ftTagReadRaw(string $path, bool $withCover): array {
    $out = ['title' => '', 'artist' => '', 'album' => '', 'hasCover' => false, 'cover' => null];
    if (!is_file($path) || !is_readable($path)) return $out;
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

    if ($ext === 'mp3') {
        $cover = null;
        $fh = @fopen($path, 'rb');
        if ($fh) {
            $head = fread($fh, 10);
            if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
                $size = ftTagSyncSafe(substr($head, 6, 4));
                if ($size > 0) {
                    // Ohne Coverbedarf reichen die ersten 1 MB: Textframes stehen
                    // vorne, das Bild (APIC) wird nur uebersprungen.
                    $limit = $withCover ? $size : min($size, 1048576);
                    $tags  = ftTagParseId3v2($head . (string)fread($fh, $limit), $withCover, $cover);
                    $out['title']    = $tags['title'];
                    $out['artist']   = $tags['artist'];
                    $out['album']    = $tags['album'];
                    $out['hasCover'] = $tags['hasCover'];
                }
            }
            if ($out['title'] === '' || $out['artist'] === '' || $out['album'] === '') {
                if (fseek($fh, -128, SEEK_END) === 0) {
                    $v1 = ftTagParseId3v1((string)fread($fh, 128));
                    foreach (['title', 'artist', 'album'] as $k) {
                        if ($out[$k] === '' && $v1[$k] !== '') $out[$k] = $v1[$k];
                    }
                }
            }
            fclose($fh);
        }
        $out['cover'] = $cover;
        return $out;
    }

    if (in_array($ext, ['m4a', 'mp4', 'm4b', 'aac'], true)) {
        $cover = null;
        $fh = @fopen($path, 'rb');
        if ($fh) {
            $stat  = fstat($fh);
            $fsize = $stat ? (int)$stat['size'] : 0;
            if ($fsize > 0) {
                $tags = ftTagParseMp4($fh, $fsize, $withCover, $cover);
                $out['title']    = $tags['title'];
                $out['artist']   = $tags['artist'];
                $out['album']    = $tags['album'];
                $out['hasCover'] = $tags['hasCover'];
            }
            fclose($fh);
        }
        $out['cover'] = $cover;
        return $out;
    }

    return $out;   // wav/ogg/flac: kein Tag-Lesen (Rueckfall auf Dateiname)
}

/** Eingebettetes Cover einer Datei (oder null). */
function ftTagEmbeddedCover(string $path): ?array {
    $t = ftTagReadRaw($path, true);
    return $t['cover'];
}

// ── Cache ────────────────────────────────────────────────────────────────────
// Das Auslesen aller Tags ist bei grossen Sammlungen spuerbar. Gespeichert
// werden nur die Texte (kein Bild); der Schluessel enthaelt Groesse und
// Aenderungszeit, damit geaenderte Dateien automatisch neu gelesen werden.

function ftTagCacheFile(): string { return __DIR__ . '/audio/.tags-cache.json'; }

/** Cache einmal je Anfrage laden. */
function ftTagCacheData(): array {
    if (!isset($GLOBALS['ft_tag_cache']) || !is_array($GLOBALS['ft_tag_cache'])) {
        $GLOBALS['ft_tag_cache']       = [];
        $GLOBALS['ft_tag_cache_dirty'] = false;
        $f = ftTagCacheFile();
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            if ($raw !== false) {
                $j = json_decode($raw, true);
                if (is_array($j)) $GLOBALS['ft_tag_cache'] = $j;
            }
        }
    }
    return $GLOBALS['ft_tag_cache'];
}

/** Geaenderten Cache zurueckschreiben (einmal am Ende der Anfrage aufrufen). */
function ftTagCacheFlush(): void {
    if (empty($GLOBALS['ft_tag_cache_dirty'])) return;
    $f   = ftTagCacheFile();
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $json = json_encode(ftTagCacheData(), JSON_UNESCAPED_UNICODE);
    if ($json !== false) {
        @file_put_contents($f, $json, LOCK_EX);
        $GLOBALS['ft_tag_cache_dirty'] = false;
    }
}

/**
 * Tags mit Cache: liefert title/artist/album/hasCover.
 * $key identifiziert die Datei stabil (z. B. "Album/Datei.mp3").
 */
function ftTagRead(string $path, string $key): array {
    $cache = ftTagCacheData();
    $size  = @filesize($path);
    $mtime = @filemtime($path);
    if ($size === false || $mtime === false) {
        return ['title' => '', 'artist' => '', 'album' => '', 'hasCover' => false];
    }
    $sig = $size . ':' . $mtime;
    if (isset($cache[$key]) && ($cache[$key]['sig'] ?? '') === $sig) {
        $e = $cache[$key];
        return [
            'title'    => (string)($e['t'] ?? ''),
            'artist'   => (string)($e['a'] ?? ''),
            'album'    => (string)($e['l'] ?? ''),
            'hasCover' => (bool)($e['c'] ?? false),
        ];
    }

    $raw = ftTagReadRaw($path, false);
    $cache[$key] = [
        'sig' => $sig,
        't'   => $raw['title'],
        'a'   => $raw['artist'],
        'l'   => $raw['album'],
        'c'   => (bool)$raw['hasCover'],
    ];
    $GLOBALS['ft_tag_cache']       = $cache;
    $GLOBALS['ft_tag_cache_dirty'] = true;

    return [
        'title'    => $raw['title'],
        'artist'   => $raw['artist'],
        'album'    => $raw['album'],
        'hasCover' => (bool)$raw['hasCover'],
    ];
}
