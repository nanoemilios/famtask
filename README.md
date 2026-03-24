# FamTask

FamTask ist eine lokale Familien-Task-App mit HTML-Frontend und PHP-Backend (MariaDB).

Dieses Repository enthält mehrere Entwicklungsstände. Die **aktuelle Version** ist:

- `v1.2`

Ältere Ordner (`v0.1.9`, `v1.0`, `v1.1`) sind historische Versionen.

## Features (v1.2)

- Aufgabenverwaltung für Familie/Kinder
- Statistik-Endpunkte (`action=stats`)
- Familien-Registrierung/Verifizierung (`action=register`, `action=verify`)
- iCal-Import (`action=ical`)
- Installer/Update direkt über `api.php`

## Projektstruktur

```text
famtask/
├─ v1.2/                 # aktuelle Version
│  ├─ index.html         # Frontend
│  ├─ api.php            # Backend + Installer + API
│  ├─ famtask.sql        # SQL-Dump (optional / lokal)
│  └─ ...
├─ v1.1/                 # alte Version
├─ v1.0/                 # alte Version
└─ v0.1.9/               # alte Version
```

## Voraussetzungen

- Docker Desktop **oder** lokaler Webserver mit PHP 8.1+
- MariaDB/MySQL

## Lokal starten (dein Setup)

Wenn deine Installation bereits über Docker lokal läuft (z. B. `http://localhost:8888/`), kannst du sie direkt weiterverwenden.

> Hinweis: Die lokale App-Installation liegt bei dir unter `C:\Users\nano\OneDrive\CODDING\devenv\apps\famtask`.

## Sicherheit / Sensitive Daten

- **Nicht committen:** `.famtask_cfg.php` (enthält Zugangsdaten)
- Produktions-Passwörter nicht hartcodieren
- SQL-Dumps vor Veröffentlichung auf persönliche Daten prüfen

## Dokumentation

- GitHub Upload: `docs/GITHUB_UPLOAD_DE.md`
- Lokales Docker-Setup (`devenv`): `docs/LOCAL_SETUP_DEVENV_DE.md`

## Lizenz

Aktuell ist keine Lizenz definiert. Wenn du das Projekt öffentlich machst, ergänze eine passende Lizenz (z. B. MIT).
