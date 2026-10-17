# Changelog

Alle nennenswerten Änderungen an FamTask.

Format basierend auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

---

## [1.5.0] – 2026-10-15

### Hinzugefügt

**Sackgeld (CHF)**
- Kinder verdienen in Schweizer Franken, Beträge werden in Rappen als Integer
  gespeichert (kein Rundungsfehler durch Gleitkommazahlen)
- Sparkonto mit getrenntem Guthaben
- Wochenvergütung automatisch
- Kontoauszug über alle Buchungen
- Negative Guthaben möglich (Kinder können in die Schuld geraten)
- Kaufbuchungen: Guthaben gegen Medienzeit eintauschen

**Jukebox – Player pro Kind neu gestaltet**
- Kind-Auswahl oben; die Farbe des aktiven Kindes wird zur Akzentfarbe der
  gesamten Ansicht
- Waagerechtes Album-Cover-Carousel mit Scroll-Snap
- Schlanke Titelliste statt Task-Kacheln
- Schwebender Mini-Player mit Cover, Fortschritt und Vor/Zurück
- Pro Kind gespeichert (serverseitig, Key `ft-jb`): letzter Titel, letztes
  Album, eigene Album-Reihenfolge
- **Favoriten je Kind**: Herz an jedem Titel, eigener Reiter „Meine Lieder"

**Externe Musikquellen** (`remote.php`)
- WebDAV (PROPFIND über cURL), FTP/FTPS, HTTPS/S3 und gemountete Laufwerke
- Import als **Kopieren** (offline verfügbar) oder **Verlinken** (Symlink)
- Wiederholte Importe überspringen unveränderte Dateien statt Dubletten anzulegen
- Zugangsdaten liegen ausschliesslich in der Datenbank und werden nie
  an den Client zurückgegeben
- Pfad-Traversal, `file://`, Zugangsdaten in der URL und Cloud-Metadaten-
  Endpunkte werden abgewiesen
- Admin-Reiter „Musikquellen" mit Verbindungs-Test und Ordner-Ansicht

**Fokus-Fehler behoben**
- Der Cursor sprang beim Tippen in Notiz- und PIN-Feldern in ein anderes Feld
  zurück. Ursache waren Komponenten, die bei jedem Rendern neu erzeugt wurden
  und dadurch Eingabefelder mitten im Schreiben neu aufsetzten.

**Jukebox – Musik-Metadaten und passende Cover**
- `id3.php`: eigener, schlanker Parser für ID3v2.3/2.4 und ID3v1 (MP3) sowie
  iTunes-Atome (M4A/MP4). Gelesen werden Titel, Interpret, Album und das
  eingebettete Cover; Ergebnisse liegen in `audio/.tags-cache.json`, damit
  nicht bei jedem Aufruf alle Dateien geöffnet werden.
- Titel, Interpret und Album kommen aus den Tags. Fehlen sie, gilt weiterhin
  Dateiname und Ordnername.
- Ein vorhandenes eingebettetes Bild wird automatisch als Album-Cover
  übernommen (`audio/.covers/<Ordnername>.jpg`), sofern dort noch keins liegt.
  Ein von Hand abgelegtes Cover bleibt unangetastet.
- Neuer Endpunkt `jukebox.php?action=tagcover` liefert das eingebettete Bild
  einer einzelnen Datei.

### Geändert
- Komponenten mit eigenen Eingabefeldern (Modals, Admin, Kind-Screen,
  Musik-Player) liegen jetzt auf oberster Ebene und bekommen ihren Kontext
  über ein `ui`-Prop statt über Closures
- `AudioPlayerScreen` lädt Server-Tracks nur noch einmal, auch wenn die
  Komponente erneut mountet (Wächter `_tracksLoading`)
- Cover-Autoloaden schreibt jetzt in den Album-Baum, in dem die UI tatsächlich
  rendert – vorher wurden gefundene Cover verworfen
- MusicBrainz-Abfrage ohne den ungültigen Parameter `inc=cover-art-archives`
- Dockerfile: PHP-Extensions `curl` und `ftp` ergänzt
- `docker-compose.yml`: kommentierte Beispiele für SMB-/NFS-Bind-Mounts und
  die Variable `FAMTASK_REMOTE_ROOTS`
- Cover-Suche fragt Album und Titel getrennt ab und beurteilt jeden Treffer
  gegen den gesuchten Namen. Gesucht wird einmal pro Album statt pro Titel.
- Nur noch iTunes, Deezer und MusicBrainz werden befragt. YouTube-Thumbnails
  und die Bing-Bildersuche sind entfernt – sie lieferten praktisch beliebige
  Bilder und waren die häufigste Ursache für falsche Cover.
- Gefundene Cover bleiben im Browser gespeichert (`ft-cover-cache`); einmalig
  werden dabei die mit der alten Suchlogik gefundenen Cover verworfen.

### Behoben
- Cover-Autoloaden der Jukebox lieferte nie sichtbare Cover
- Doppeltes Laden der Server-Tracks überschrieb bereits geladene Cover
- **Falsche Cover:** Der Suchbegriff wurde am ersten Bindestrich abgeschnitten.
  Bei Dateien wie `01 - Hexerei.mp3` wurde deshalb nur die Tracknummer `01`
  gesucht – und die Suche lieferte irgendein fremdes Bild. Zusätzlich fehlte
  jede Prüfung, ob der Treffer überhaupt zum Titel oder Album passt.
- 403 eines WebDAV-Servers wurde als 502 gemeldet statt als 401
- Zwei Player gleichzeitig auf dem Jukebox-Screen
- Versionsanzeige stand in Login, Footer und Rechtstexten noch auf `v1.4.0`
- README nannte 21 Sprachen, tatsächlich sind es 19
- 54 fehlende Übersetzungs-Schlüssel (`audio.*`, `admin.music_tab`,
  `remote.*`) in allen 17 weiteren Sprachen ergänzt (Spielertitel pro Kind,
  Favoriten, externe Musikquellen)
- Gemischtsprachige Zeichenkette bei „Simon Says" (Arabisch) korrigiert
- Koreanisch: falscher Platzhalter `{uses}` in `media.timer_stopped` → `{used}`

### Sicherheit
- Zugangsdaten externer Quellen werden nie an den Client ausgeliefert
- Lokale Quellen sind auf konfigurierte Stammpfade beschränkt
- iCal-Import (`api.php?action=ical`): SSRF-Prüfung gehärtet – nur
  öffentliches HTTPS, private/reservierte Netze und Cloud-Metadaten-Endpunkte
  werden auch nach DNS-Auflösung des Hostnamens abgewiesen
- Hinweise dokumentiert, dass FamTask keine echte Benutzerverwaltung hat –
  der Familiencode ist keine Authentifizierung
- **Admin-Endpunkte von `api.php` abgesichert:** `settings`, `save_settings`,
  `families`, `rename_family`, `delete_family`, `backup`, `restore`, `update`,
  `stats`, `ytplaylist` sowie `install`/`testdb` (bei bestehender Installation)
  verlangten bisher **keine** Anmeldung. Dadurch waren der Admin-PIN im
  Klartext auslesbar, Familien löschbar und die Datenbank-Konfiguration
  überschreibbar. Sie prüfen jetzt dieselbe Admin-Anmeldung wie das Panel.
- **Admin-Anmeldung mit Zufalls-Session** (`famtask_admin_sessions`) statt des
  vorhersagbaren Cookies `sha1('ft_admin_' + PIN)`
- **Brute-Force-Bremse** für die 4-stellige PIN (`famtask_auth_guard`): maximal
  8 Fehlversuche pro IP in 15 Minuten
- **`remote.php` abgesichert:** alle Aktionen ausser `status` (`list`, `save`,
  `delete`, `browse`, `test`, `import`) verlangen jetzt eine Admin-Anmeldung.
  Bisher war die Datei komplett offen – Fremde konnten Musikquellen anlegen und
  den Server fremde URLs abrufen lassen (SSRF).

### Bekannte Einschränkungen
- `AudioPlayerScreen` und die Spiele werden bei jedem Rendern neu erzeugt.
  Das ist Performance-unkritisch, aber zwingt UI-Zustand in `FamTask` statt in
  die Komponente zu legen.
- Sehr allgemeine Ordner- und Albumnamen (z. B. „Album A", „CD1") können
  weiterhin kein oder ein unpassendes Cover liefern – die Prüfung lehnt
  Unklares lieber ab. Am besten wirkt ein Ordnername der Form „Serie + Folge"
  (z. B. „Bibi Blocksberg 12") oder ein gepflegtes ID3-Album. Ein eigenes Cover
  lässt sich jederzeit als `audio/.covers/<Ordnername>.jpg` ablegen
  (Leerzeichen werden zu `_`, Sonderzeichen zu `_`).
- Ohne Metadaten (WAV/OGG, Dateien ohne Tags) bleibt es beim Dateinamen als
  Titel; solche Ordner finden nur dann ein Cover, wenn der Ordnername passt.
- S3 wird nur über direkte oder presigned HTTPS-URLs unterstützt, nicht über
  SigV4-Signierung mit Bucket und Access Key.
- Ein automatisierter Re-Sync (Cronjob) fehlt; Importe laufen von Hand.

---

## [1.4.0] – 2026-09-24

### Hinzugefügt
- Medienverwaltung mit Wochenlimit und erlaubten Tagen
- Admin-Bereich für Strafen, Termine und Einstellungen
- iCal-Import für Google Kalender
- Kompilationsschutz: `check-jsx`, `check-props`, `check-input-focus`

---

## [1.3.0] – 2026-09-10

### Hinzugefügt
- Spiele mit Highscore pro Kind
- Spiel-Belohnung in Freizeitminuten

---

## [1.2.0] – 2026-08-15

### Hinzugefügt
- MariaDB-Sync zwischen Geräten
- Jukebox mit lokalen Audiodateien

---

## [1.1.0] – 2026-07-20

### Hinzugefügt
- Aufgaben, Medien, Timer
- Installierbare PWA
