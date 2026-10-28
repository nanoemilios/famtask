# Changelog

Alle nennenswerten Änderungen an FamTask.

Format basierend auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

---

## [Unveröffentlicht]

### Geändert

**Icon & PWA**
- Neues Favicon-/App-Icon: F und Haken als getrennte Formen auf der
  Marken-Kachel (Gradient `#FF6B6B` → `#FFE66D`, Grund `#0D0E1A`)
- Vollständiges Set in `icons/`: `favicon.ico` (16–64 px), `favicon.svg`,
  PNG-Favicons, `apple-touch-icon.png` (iOS) und `safari-pinned-tab.svg`
- `manifest.json` mit 192/512-px-Icons für `any` und `maskable` (Safe-Zone)
- PWA bleibt installierbar (Chrome/Edge/Android); das Caching übernimmt jetzt
  der Service Worker (siehe „Ladezeit“)
- Installationsanleitung auf iPhone/iPad korrigiert: iPadOS im Desktop-Modus
  und Chrome/Edge unter iOS bekamen die Android-Schritte. Die drei Schritte
  wurden außerdem als „p w a“ statt als Text angezeigt, weil `t()` Listen
  nicht zurückgab
- Die Anleitung nennt jetzt „iPhone / iPad“ statt „iOS Safari“ und verweist
  ohne Positionsangabe auf die Browser-Leiste (iPad zeigt das Teilen-Symbol
  oben, das iPhone unten)
- `tools/build_icons.py` erzeugt das Set reproduzierbar aus einer Geometrie

**Barrierefreiheit & Design-Feinschliff**
- Marken-Icon statt ✨ in Kopfzeile und Login, links neben der Wortmarke
- `<main>`-Landmark und „Zum Inhalt springen“-Link; Zoom im Browser wieder
  erlaubt (`viewport-fit=cover` statt `user-scalable=no`)
- Sichtbare Fokusringe für Tastaturbedienung (`:focus-visible`)
- `prefers-reduced-motion` wird respektiert, Safe-Area für Notch/Home-Indikator
- Alle Formularfelder haben Programmnamen (`aria-label`), Icons-Buttons wie
  ✏️/🗑️/📋 sind für Screenreader benannt
- Kind-Karten sind per Tastatur bedienbar (`role="button"`, Enter/Leertaste)
- Kontraste über alle Ansichten angehoben (u. a. Hinweise, Metadaten,
  inaktive Filter, ausgewählte Tage/Chips mit dunkler Schrift)
- Neue Übersetzungsschlüssel `a11y.*` in allen 19 Sprachen; `common.edit`
  ergänzt (fehlte überall und wurde als roher Schlüssel angezeigt)
- Der iCal-Hinweis im Admin (`admin.enter_text`) war außerhalb des Deutschen
  nur ein Satzzeichen; jetzt überall ein vollständiger Satz

**Ladezeit**
- React-Quelltext nach `src/app.jsx` ausgelagert; `app.js` wird mit
  `node tools/build_app.mjs` vorgebaut und mitgeliefert
- `index.html` schrumpft von 313 KB auf 8 KB, `vendor/babel.min.js` (2,4 MB)
  wird nur noch im Entwicklungsmodus geladen
- Ohne Build bleibt alles lauffähig: `?dev=1` an der URL oder eine fehlende
  `app.js` laden `src/app.jsx` und übersetzen es im Browser
- Erster Aufruf rund 630 KB → 185 KB (gzip, inklusive Schriften);
  DOMContentLoaded im Test von 15–30 s auf unter 0,5 s
- `app.js` bekommt einen Inhalts-Hash als `?v=…`, damit nach einem Update
  nicht die alte Datei aus dem Browser-Cache läuft
- Auf dem Webserver sind weiterhin weder Node noch npm nötig
- `.htaccess` liefert gzip für HTML/CSS/JS/JSON/SVG und setzt Cache-Dauern:
  ein Jahr für Icons und Schriften (`immutable`), eine Woche für CSS/JS,
  `no-cache` für HTML und `sw.js`, `no-store` für PHP
- Service Worker mit App-Shell-Cache: `index.html` kommt netzwerk-zuerst
  (Updates kommen an), alle App-Dateien aus dem Cache. Beim Wiederbesuch
  entsteht für das Gerüst kein Netzverkehr mehr, die App startet auch offline
- Sprachdatei lädt `index.html` per `document.write` statt synchronem XHR;
  dadurch liefert der Service Worker sie aus dem Cache, und die App startet
  offline in der zuletzt gewählten Sprache
- `.htaccess` bleibt die Apache-Variante; die nginx-Entsprechung steht in
  `famtask_docs/DEPLOYMENT.md`

**Audio-Player & Streaming**
- Grosse Titel (Hörspiele, ~50 MB) starten sofort: `preload="metadata"` statt
  `"auto"`, kein doppelter `load()`-Aufruf mehr; die Ladeanzeige (⏳) zeigt,
  solange der Stream noch puffert
- `jukebox.php` liefert Audio in Blöcken aus statt die Datei in den Speicher zu
  laden, beherrscht Suffix-Ranges (`bytes=-1024`, nutzt Safari/iOS) und
  antwortet bei ungültigen Bereichen mit 416
- `Content-Length` bleibt auch bei aktivierter PHP-Kompression erhalten; fehlt
  er, zeigen Browser bei MP3 die Dauer als „Infinity“ und laden die ganze Datei
- Unbekannte Dauer erscheint als „–:––“ statt „Infinity:NaN“ und wird
  nachgezogen, sobald der Browser die Länge kennt (`durationchange`)
- Audio-Antworten sind eine Stunde cachebar (`private, max-age=3600`) statt
  `no-store`

**Design-System & Social-Sharing**
- Design-Tokens in `styles.css` (`:root`): Farben, Verläufe, Text- und
  Linienwerte, Radien und Schatten an einer Stelle; über 140 Verwendungen
  nutzen jetzt `var(--ft-…)`
- Einheitliche Buttons und Karten: 44 px Mindesthöhe, gleiche Radien,
  Hover-/Aktiv-Zustände, deaktivierte Buttons sichtbar unterscheidbar
- Geteilte Links zeigen eine Vorschau: `icons/og-image.png` (1200×630) plus
  Open-Graph- und Twitter-Meta; das Bild entsteht reproduzierbar über
  `tools/build_og.py`
- `vendor/fonts/README.md` dokumentiert Herkunft und OFL-Lizenz der Schriften

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
- Findet sich kein Album-Cover, wird für den einzelnen Titel gesucht. So bekommen
  Sammlungs-Ordner wie „Mami" trotzdem das Cover der enthaltenen Singles.

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
  werden dabei die mit der alten Suchlogik gefundenen Cover verworfen – seit
  diesem Fix auch die Trefferliste selbst, sonst blieben falsch gefundene Cover
  im Browser hängen.
- Dateinamen im Muster `Interpret - Titel (Zusatz)` werden zerlegt. Vorher galt
  der ganze Dateiname als Titel, weshalb der Vergleich an Zusätzen wie `(Lyrics)`
  oder `[Official Video]` scheiterte und die Suche nichts mehr fand.
- Der Ordnername zählt nur dann als Album-Suchbegriff, wenn er wie ein Album
  aussieht (Ziffer oder mindestens zwei Wörter). „Mami", „Papi", „Samuel" und
  „Santiago" sind Sammlungen beziehungsweise Vornamen.
- Album-Treffer werden gegen Interpret **und** Albumname geprüft. Bisher genügte
  der Albumname: „Hurra Kinderlieder" passte auch auf „Mutonia Kinderlieder
  Helau Hurra Karneval Faschings Kinderlieder", „Santiago" auf das Album
  „Santiago" der Chieftains.
- Die Titel-Suche wird ohne Interpret wiederholt, wenn die Suche mit Interpret
  nichts liefert (Deezer findet „Nuria Fergó y Manu Tenorio Noches de Bohemia"
  nicht, „Noches de Bohemia" schon).
- MusicBrainz wird nur noch mit einem aussagekräftigen Suchbegriff befragt
  (Serienname statt eines einzelnen Wortes wie „Der Zoo").

### Behoben
- Cover-Autoloaden der Jukebox lieferte nie sichtbare Cover
- Doppeltes Laden der Server-Tracks überschrieb bereits geladene Cover
- **Falsche Cover:** Der Suchbegriff wurde am ersten Bindestrich abgeschnitten.
  Bei Dateien wie `01 - Hexerei.mp3` wurde deshalb nur die Tracknummer `01`
  gesucht – und die Suche lieferte irgendein fremdes Bild. Zusätzlich fehlte
  jede Prüfung, ob der Treffer überhaupt zum Titel oder Album passt.
- **Falsche Cover bei Sammlungs-Ordnern:** Enthielt ein Ordner nur einen Namen
  wie „Mami" oder „Papi", lieferte die Album-Suche irgendein gleichnamiges
  Album (etwa „Mami - Single" von Emmanuel Cortes) und zeigte es als Cover des
  Titels an. Solche Ordner werden jetzt nicht mehr als Album gesucht; stattdessen
  wird der Titel gesucht und das Cover verifiziert.
- **Falsche Cover bei Duetten:** Die Interpret-Prüfung verlangte volle
  Übereinstimmung. „Nuria Fergó" wurde deshalb zu „Nuria Fergó y Manu Tenorio"
  abgelehnt und das richtige Cover verworfen.
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
