# FamTask - Hauptanwendung

Familien-Aufgaben-App mit HTML-Frontend (React) und PHP-Backend (MariaDB).

## Features (v1.5.0)

### Aufgaben & Belohnungen
- Aufgabenverwaltung für Kinder mit täglicher Wiederholung, Fälligkeitszeiten und Bild-Upload
- Belohnungssystem: Minuten für erledigte Aufgaben sammeln
- Strafenkatalog mit automatischer Minuten-Verrechnung & Live-Tracking (Fine Hits)
- Zuweisung zu einzelnen Kindern oder mehreren

### Sackgeld (CHF)
- Kinder verdienen in Schweizer Franken, Beträge werden in Rappen als Integer gespeichert
- Sparkonto mit getrenntem Guthaben und Wochenvergütung
- Kontoauszug über alle Buchungen, negative Guthaben möglich
- Kaufbuchungen: Guthaben gegen Medienzeit eintauschen

### Medienzeit-Verwaltung
- Individuelle Limits pro Kind und Medientyp (TV, Tablet, Nintendo, eigene Typen)
- Tägliche & wöchentliche Limits, Erlaubte Wochentage
- Minuten-Übertrag (Carry-Over) konfigurierbar
- Echtzeit-Timer mit Alarm-Sound für jedes Kind

### Familien-Kalender
- Kalender mit Terminen, Wiederholungen (wöchentlich/monatlich/jährlich)
- Google-Kalender-Import via iCal
- Kind-Filter & Ganztägige Events

### Pädagogische Spiele (14 Stück)
- Mathe, Memory, Wort-Quiz, Tic-Tac-Toe, Geographie, Simon Says
- Uhr lesen, Galgenmännchen, Memory Recall, Wort-Scramble, Würfelzählen
- Gegenteile, Größenvergleich, Tier-Quiz
- Automatische Schwierigkeitsanpassung nach Alter
- Minuten-Belohnungen & Bestenliste

### Jukebox / Audio-Player
- **Pro Kind personalisiert**: eigene Farbe, eigenes Album, eigener letzter Titel
- Kind-Auswahl oben, waagerechtes Album-Cover-Carousel, schlanke Titelliste
- **Favoriten je Kind** (Herz an jedem Titel, Reiter „Meine Lieder")
- Schwebender Mini-Player mit Cover, Fortschritt und Vor/Zurück
- Automatisches Cover-Art-Fetching (MusicBrainz, Cover Art Archive, iTunes)

### Externe Musikquellen
- Musik von **WebDAV, FTP/FTPS, HTTPS/S3** und **gemounteten Laufwerken**
- Zwei Importmodi: **Kopieren** (offline verfügbar) oder **Verlinken** (Symlink, kein Speicherbedarf)
- Wiederholte Importe überspringen unveränderte Dateien
- Zugangsdaten bleiben ausschliesslich auf dem Server
- Pfad-Traversal, `file://` und Cloud-Metadaten-Endpunkte werden abgewiesen

> **SMB/CIFS und NFS:** PHP kann Netzwerkdateisysteme nicht selbst mounten.
> Der Host mountet, Docker reicht durch – siehe [famtask_docker](https://github.com/nanoemilios/famtask_docker).

### Administration
- Admin-PIN-Schutz
- Familienverwaltung (erstellen/umbenennen/löschen)
- Reiter „Musikquellen" mit Verbindungs-Test und Ordner-Ansicht
- Globale Einstellungen & Admin-Panel via `api.php?action=admin`
- Backup/Restore via `action=backup` / `action=restore`
- Statistik-Endpunkte (`action=stats`)

### Technisch
- Multi-Language (i18n) – Deutsch Standard, erweiterbar
- Echtzeit-Sync zwischen Geräten via MariaDB
- PWA-Support (installierbar, Service Worker, Offline-Fallback)
- Mobile-First, Dark Mode
- Installer/Update direkt über `api.php`

## Voraussetzungen

- PHP 8.0+ mit `pdo_mysql` und `mbstring`
- Für externe Musikquellen zusätzlich: `curl` (WebDAV, HTTPS/S3) und
  `ftp` (FTP/FTPS) – im Docker-Image bereits enthalten
- MariaDB/MySQL
- Webserver (Apache/Nginx) oder Docker

## Dateien

| Datei | Zweck |
|---|---|
| `index.html` | Oberfläche und gesamte App-Logik (React, ohne Build-Schritt) |
| `api.php` | Sync, Familien, Einstellungen, Kalender, Admin |
| `jukebox.php` | Musik-Scan, Streaming, Cover, Upload, Verwaltung |
| `remote.php` | Externe Musikquellen: WebDAV, FTP, HTTPS/S3, Mounts |
| `styles.css` | Gestaltung |
| `lang/*.js` | Übersetzungen (19 Sprachen) |
| `audio/` | Musikdateien, Unterordner = Alben |

## Installation

### Option 1: Web-Installer (empfohlen für Shared Hosting)
Lade den [Web-Installer](https://github.com/nanoemilios/famtask_installer) herunter und folge den Anweisungen.

### Option 2: Docker (empfohlen für VPS/Proxmox)
Nutze das [Docker-Setup](https://github.com/nanoemilios/famtask_docker) mit dem [Proxmox-Installer](https://github.com/nanoemilios/famtask_proxmox):

```bash
# One-Liner (empfohlen):
bash <(curl -fsSL https://raw.githubusercontent.com/nanoemilios/famtask_proxmox/main/proxmox-install-oneliner.sh)

# Oder manuell:
git clone https://github.com/nanoemilios/famtask_proxmox.git
cd famtask_proxmox
bash proxmox-install.sh
```

### Option 3: Manuell
```bash
git clone https://github.com/nanoemilios/famtask.git
cd famtask
# Konfiguriere .famtask_cfg.php mit deinen DB-Zugangsdaten
# Rufe im Browser auf: /api.php?action=admin
```

## Konfiguration

Kopiere `.famtask_cfg.php.example` zu `.famtask_cfg.php` und passe an:

```php
<?php if(!defined('FAMTASK'))die('403'); return array (
  'host' => 'localhost',
  'port' => 3306,
  'db' => 'famtask',
  'user' => 'famtask',
  'pass' => 'dein_passwort',
); ?>
```

Optional über die Umgebungsvariable `FAMTASK_REMOTE_ROOTS` (Komma-getrennt)
einschränken, aus welchen Verzeichnissen „Gemountetes Laufwerk"-Quellen lesen
dürfen. Standard: `/mnt,/media,/srv,/net,/Volumes`.

## Sicherheit

> ⚠️ **FamTask hat keine echte Benutzerverwaltung.** Der Familiencode ist keine
> Authentifizierung – er steht im Klartext im lokalStorage jedes Geräts, und
> jeder, der ihn kennt, kann über `api.php` Daten lesen und schreiben.
>
> **FamTask deshalb nicht ungeschützt ins Internet stellen.** Empfehlung:
> nur im Heimnetz betreiben oder einen Reverse-Proxy mit Basic-Auth davorsetzen.

Zugangsdaten externer Musikquellen liegen ausschliesslich in der Datenbank
und werden von `remote.php` nie an den Client zurückgegeben.

## Icons und Web-App-Manifest

Das Favicon-/App-Icon-Set liegt komplett in `icons/` und wird nicht von Hand
gepflegt, sondern aus der Geometrie in `tools/build_icons.py` erzeugt:

```bash
python3 tools/build_icons.py                  # komplettes Set nach icons/
python3 tools/build_icons.py --favicon-only   # nur Favicon/iOS (Landingpage)
```

Erzeugt werden: `favicon.ico` (16–64 px), `favicon.svg`, PNG-Favicons in
16/32/48 px, `apple-touch-icon.png` (180 px, randlos für iOS), PWA-Icons in
192/512 px für `any` und `maskable` (Safe-Zone 78 %) sowie
`safari-pinned-tab.svg`. Voraussetzung: Pillow und Chrome headless
(`CHROME` überschreibbar, Standard `~/.local/opt/chrome-headless-shell/`).

`manifest.json` verweist auf diese Dateien; `sw.js` ist ein absichtlich
cacheloser Service Worker mit leerem `fetch`-Handler, damit die App in Chrome,
Edge und Android als PWA installierbar bleibt.

## Verwandte Repositories

- **famtask_installer** – Web-basierter Installer/Updater
- **famtask_docker** – Docker Compose Setup + Proxmox Installer
- **famtask_docs** – ausführliche Dokumentation
- **famtask_proxmox** – Proxmox-/LXC-Installer

## Versionshistorie

Siehe [CHANGELOG.md](CHANGELOG.md).

## Lizenz

Keine Lizenz definiert. Für öffentliche Nutzung bitte Lizenz hinzufügen (z.B. MIT).