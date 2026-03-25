# FamTask

FamTask ist eine lokale Familien-Task-App mit HTML-Frontend und PHP-Backend (MariaDB).

Die **aktuelle Version** liegt in:

- `v1.2`

Historische Versionen wurden aus dem aktiven Projektstand entfernt und sind nicht Teil des regulären Workflows.

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
│  ├─ .htaccess          # Apache-Regeln
│  ├─ famtask.sql        # SQL-Dump (optional / lokal)
│  └─ .famtask_cfg.php   # lokale DB-Konfig (nicht committen)
├─ docs/                 # Projektdokumentation
└─ README.md
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
- Architektur/API-Überblick: `docs/ARCHITECTURE_DE.md`
- Changelog: `CHANGELOG.md`

## Lizenz

Aktuell ist keine Lizenz definiert. Wenn du das Projekt öffentlich machst, ergänze eine passende Lizenz (z. B. MIT).
