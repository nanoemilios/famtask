# FamTask Architektur (v1.2)

## Überblick

FamTask v1.2 ist eine einfache Web-App mit:

- Frontend: `v1.2/index.html`
- Backend/API + Admin/Installer: `v1.2/api.php`
- Webserver-Regeln: `v1.2/.htaccess`
- Datenbank: MariaDB/MySQL

## Backend-API (Auszug)

`v1.2/api.php` verarbeitet Actions über Query-Parameter:

- `action=install` – Installation/Initialisierung
- `action=status` – Installationsstatus
- `action=update` – Migrationen anwenden
- `action=track` – Tracking-Events speichern
- `action=stats` – Statistikdaten liefern
- `action=register` – Familie registrieren
- `action=verify` – Familiencode prüfen
- `action=families` – Familien auflisten
- `action=ical` – iCal-Termine importieren

## Versionierung/Migrationen

Die App-Version ist in `APP_VERSION` definiert und Migrationen sind in `$MIGRATIONS` hinterlegt.

## Konfiguration

Lokale Konfigurationsdatei:

- `.famtask_cfg.php` (DB-Host, Port, Datenbank, User, Passwort)
- Beispiel: `v1.2/.famtask_cfg.example.php` (für GitHub, ohne echte Zugangsdaten)

`v1.2/.famtask_cfg.php` ist sensitiv und sollte nie ins öffentliche Repository.
