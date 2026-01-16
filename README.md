# LocalSupervisor (SuperVisOr) – Projekt-Diagnose

## Überblick
Dieses Repository enthält ein lokales Medien-Management-System mit PHP-Weboberfläche, CLI-Workflows (Scan/Rescan/Jobs) und einem Node-basierten VA/VIDAX-Setup. Ziel ist die Verwaltung von Medien, Metadaten und Job-Flows ohne Cloud-Abhängigkeit.

## Vollständige Diagnose (Ist-Zustand)
### Architektur & Komponenten
- **Weboberfläche (PHP):** `WWW/` stellt Dashboard, Galerie und Detailansicht bereit. HTTP-Routen laufen über PHP-Skripte. 
- **Kernel/CLI (PHP):** `SCRIPTS/` enthält die Kernlogik für Scan, Jobs, DB-Status, Backups und Konsistenz. 
- **Datenbank:** Referenzschema liegt in `DB/schema.sql`. 
- **VA/VIDAX (Node):** Node-Komponenten liegen in `src/` und `bin/`, Einstiegspunkte sind `src/vidax/server.js` und `bin/va.js`. 
- **Konfiguration:** zentrale PHP-Config unter `CONFIG/`, Node-Example-Configs in `config/`. 
- **Runtime-Artefakte:** Logs/Backups werden zur Laufzeit unter `LOGS/` und `BACKUPS/` erzeugt (nicht im Repo enthalten).

### Einstiegspunkte
- **Windows-Start:** `start.bat` / `start.ps1`.
- **Node-Start:** `npm run start:vidax` (VIDAX-Server).
- **VA-Tools:** `npm run va:doctor` und `npm run va:install`.

### Abhängigkeiten (implizit aus Code/Struktur)
- **PHP** für `SCRIPTS/` und `WWW/`.
- **Node.js (>= 18)** für VA/VIDAX (laut `package.json`).
- **SQLite** als Standardspeicher (Schema in `DB/schema.sql`).
- **ffmpeg** optional für Video-Thumbs/Checks (CLI-Werkzeuge referenziert).

### Datenfluss (hoch-level)
1. **Import/Scan** über CLI-Worker in `SCRIPTS/`.
2. **Persistenz** in `DB/`-Schema (Media, Tags, Jobs, Prompts).
3. **Anzeige/Steuerung** über `WWW/` (Dashboard, Galerie, Detail).
4. **VA/VIDAX** für Setup/Assets und VIDAX-Serverbetrieb.

### Dokumentationsstand
Es gibt nun genau drei projektinterne Dokumente:
- `README.md` (diese Diagnose & Überblick)
- `AGENTS.MD` (Arbeitsregeln + bekannte Probleme)
- `VERSIONSLOG.MD` (künstliches Funktions-Update-Log)

> Hinweis: Es wurden **keine Tests** ausgeführt.

## Entfernte/Legacy-Dokumente
Legacy-Dokumente und redundante Checklisten wurden entfernt, um die Dokumentation auf die drei Kerndateien zu konsolidieren.

