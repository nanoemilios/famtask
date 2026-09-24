# FamTask - Hauptanwendung

Familien-Aufgaben-App mit HTML-Frontend (React) und PHP-Backend (MariaDB).

## Features (v1.4.0)

### Aufgaben & Belohnungen
- Aufgabenverwaltung für Kinder mit täglicher Wiederholung, Fälligkeitszeiten und Bild-Upload
- Belohnungssystem: Minuten für erledigte Aufgaben sammeln
- Strafenkatalog mit automatischer Minuten-Verrechnung & Live-Tracking (Fine Hits)
- Zuweisung zu einzelnen Kindern oder mehreren

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
- Musik-/Hörspiel-Player nach Alben gruppiert
- Persistent über alle Seiten (Mini-Player in der Navigationsleiste)
- Automatisches Cover-Art-Fetching (MusicBrainz, iTunes)
- Lokale Audios hochladbar

### Administration
- Admin-PIN-Schutz (Cookie-basiert, 30 Tage)
- Familienverwaltung (erstellen/umbenennen/löschen)
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

- PHP 8.0+ mit PDO `pdo_mysql`
- MariaDB/MySQL
- Webserver (Apache/Nginx) oder Docker

## Installation

### Option 1: Web-Installer (empfohlen für Shared Hosting)
Lade den [Web-Installer](https://github.com/nanoemilios/famtask_installer) herunter und folge den Anweisungen.

### Option 2: Docker (empfohlen für VPS/Proxmox)
Nutze das [Docker-Setup](https://github.com/nanoemilios/famtask_docker):

```bash
git clone https://github.com/nanoemilios/famtask_docker.git
cd famtask_docker
bash proxmox-install-oneliner.sh
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

## Verwandte Repositories

- **famtask_installer** – Web-basierter Installer/Updater
- **famtask_docker** – Docker Compose Setup + Proxmox Installer

## Lizenz

Keine Lizenz definiert. Für öffentliche Nutzung bitte Lizenz hinzufügen (z.B. MIT).