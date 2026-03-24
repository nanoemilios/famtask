# FamTask auf GitHub hochladen (Deutsch)

Diese Anleitung zeigt dir, wie du dein Projekt sauber und sicher auf GitHub veröffentlichst.

## 1) Vorbereitungen

1. Prüfe, dass die aktuelle Version in `v1.2/` liegt.
2. Prüfe sensible Daten:
   - Keine Passwörter/API-Keys in Dateien
   - `.famtask_cfg.php` darf **nicht** hochgeladen werden
3. Prüfe Datenexporte:
   - `famtask.sql` enthält ggf. persönliche Daten (Namen/Familiencode)
   - Optional anonymisieren oder aus dem öffentlichen Repo entfernen

## 2) Git-Repository initialisieren

Im Projektordner:

```bash
cd C:\Users\nano\OneDrive\CODDING\famtask
git init
git add .
git commit -m "Initial commit: FamTask v1.2 + docs"
```

## 3) GitHub-Repo erstellen

1. Auf GitHub neues Repository erstellen (z. B. `famtask`)
2. **Kein** README/.gitignore online erzeugen (weil lokal schon vorhanden)
3. URL kopieren (HTTPS oder SSH)

## 4) Remote verbinden und pushen

```bash
git branch -M main
git remote add origin <DEIN_GITHUB_REPO_URL>
git push -u origin main
```

Beispiel HTTPS:

```bash
git remote add origin https://github.com/DEIN-USER/famtask.git
```

## 5) Empfohlen: Releases und Versionen

- Tag für aktuelle Version setzen:

```bash
git tag -a v1.2.0 -m "FamTask v1.2.0"
git push origin v1.2.0
```

- Danach auf GitHub unter **Releases** eine Release-Notiz anlegen.

## 6) Optional: Öffentlich vs. Privat

- **Privat**, wenn persönliche Familiendaten enthalten sein könnten.
- **Öffentlich**, wenn alles bereinigt ist und du es teilen willst.

## 7) Optional: README verbessern

Für ein public Repo kannst du später ergänzen:
- Screenshots
- Roadmap / TODOs
- Known Issues
- Lizenzdatei (`LICENSE`)
