# Changelog

Alle relevanten Aenderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format orientiert sich an "Keep a Changelog".

## [v1.2.0] - 2026-03-25

### Added
- Dokumentation zur Architektur in `docs/ARCHITECTURE_DE.md` ergaenzt und praezisiert.
- Hinweise fuer robusten GitHub-Upload in `docs/GITHUB_UPLOAD_DE.md` erweitert.

### Changed
- `README.md` an die aktuelle Projektstruktur (fokus auf `v1.2`) angepasst.
- Doku-Verweise im `README.md` um den Architektur-Guide erweitert.

### Security
- Sensible lokale Konfiguration (`v1.2/.famtask_cfg.php`) in der Doku klarer als nicht-commitbar gekennzeichnet.

---

## [v1.2.1] - 2026-03-25

### Added
- Backup/Restore via `api.php?action=backup` und `api.php?action=restore` (Admin-Tab, JSON-Export/Import).

## Vorlage fuer naechste Releases

```md
## [vX.Y.Z] - YYYY-MM-DD

### Added
- ...

### Changed
- ...

### Fixed
- ...

### Security
- ...
```
