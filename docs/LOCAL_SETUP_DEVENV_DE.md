# Lokales Setup mit Docker Desktop (devenv)

Diese Anleitung dokumentiert dein bestehendes lokales Setup aus:

- `C:\Users\nano\OneDrive\CODDING\devenv`

Sie ist dafür gedacht, im GitHub-Repository von FamTask als nachvollziehbare Startanleitung zu dienen.

## Überblick

Deine Dev-Umgebung verwendet Docker Compose mit:

- Web/PHP-Container (`php-apache`) auf `http://localhost:8888`
- MariaDB (`mariadb`) intern auf `3306`, extern auf `localhost:3307`
- phpMyAdmin auf `http://localhost:8081`

FamTask selbst liegt lokal unter:

- `C:\Users\nano\OneDrive\CODDING\devenv\apps\famtask`

## Voraussetzungen

- Docker Desktop installiert und gestartet
- Zugriff auf den Ordner `C:\Users\nano\OneDrive\CODDING\devenv`

## Start der Umgebung

```bash
cd C:\Users\nano\OneDrive\CODDING\devenv
docker compose up -d
```

Alternativ mit dem Helper-Skript:

```bash
cd C:\Users\nano\OneDrive\CODDING\devenv
./dev.sh start
```

## App aufrufen

- App-Übersicht: `http://localhost:8888/`
- FamTask direkt: `http://localhost:8888/famtask/`
- Admin/Installer: `http://localhost:8888/famtask/api.php`
- phpMyAdmin: `http://localhost:8081/`

## Datenbank-Konfiguration für FamTask

In FamTask wird eine lokale Konfigurationsdatei verwendet:

- `.famtask_cfg.php`

Typische Werte im Docker-Setup:

- Host: `mariadb`
- Port: `3306` (interner Container-Port)
- Datenbank: `famtask`
- User: `famtask`
- Passwort: `CHANGE_ME` (nur Beispielwert)

Wichtig:

- Vor Ort die Datei `v1.2/.famtask_cfg.example.php` nach `.famtask_cfg.php` kopieren und Werte anpassen.
- Von der App aus immer `mariadb:3306` nutzen (nicht `localhost:3307`)
- `localhost:3307` ist nur für externe DB-Tools auf deinem Rechner

## Nützliche Befehle

```bash
cd C:\Users\nano\OneDrive\CODDING\devenv
docker compose ps
docker compose logs -f
docker compose down
docker compose down -v   # Achtung: löscht DB-Daten
```

Mit `dev.sh`:

```bash
./dev.sh status
./dev.sh logs
./dev.sh db
./dev.sh php
./dev.sh reset-db
```

## Troubleshooting

- Wenn `localhost:8888` nicht erreichbar ist: `docker compose ps` prüfen
- Wenn DB-Fehler auftreten: `./dev.sh db` testen und `.famtask_cfg.php` vergleichen
- Wenn Ports belegt sind: Ports in `devenv/docker-compose.yml` (oder `.env`) anpassen und neu starten

## Sicherheit für GitHub

- `.famtask_cfg.php` nicht committen (enthält Zugangsdaten)
- Für öffentliche Repos keine produktiven Passwörter verwenden
- SQL-Dumps vor dem Upload auf persönliche Daten prüfen/anonymisieren
- Backup-Dateien (JSON) enthalten ebenfalls sensible Familiendaten und sollten vor einem Upload geprüft/ggf. anonymisiert werden.
