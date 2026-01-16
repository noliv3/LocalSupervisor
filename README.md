# LocalSupervisor (SuperVisOr)

## Kurzüberblick
LocalSupervisor ist ein lokales Medien-Management-System mit PHP-Weboberfläche und CLI-Workflows für Scan/Rescan/Jobs. Ergänzend existiert ein Node-basiertes VA/VIDAX-Setup für Install/Diagnose und Serverbetrieb. Das Ziel ist eine konsistente Verwaltung von Medien, Metadaten und Job-Flows ohne Cloud-Abhängigkeit.

## Kernfunktionen (hoch-level)
- **Web-UI:** Dashboard, Galerie und Detailansicht für Medienverwaltung (PHP in `WWW/`).
- **CLI-Kernlogik:** Scan/Rescan, Job-Queue, DB-Status, Backups und Konsistenz (PHP in `SCRIPTS/`).
- **VA/VIDAX:** Setup-Utilities und VIDAX-Server (Node in `src/` und `bin/`).

## Repository-Struktur
- `WWW/` – PHP-Weboberfläche (Dashboard/Galerie/Detail/Stream/Thumb).
- `SCRIPTS/` – PHP-CLI und Kernlogik (Scan/Jobs/DB/Backups/Konsistenz).
- `DB/` – Referenzschema (`DB/schema.sql`).
- `CONFIG/` – PHP-Konfiguration.
- `config/` – Node-Example-Konfigurationen (`*.example.json`).
- `src/` / `bin/` – Node-Komponenten und CLI-Tools.
- `start.ps1` / `start.bat` – Start-/Update-Entry-Points.

## Einstiegspunkte (lokal)
- **Windows-Start:** `start.bat` oder `start.ps1`.
- **VIDAX-Server:** `npm run start:vidax`.
- **VA-Tools:** `npm run va:doctor` und `npm run va:install`.

## Laufzeit-Artefakte
- `LOGS/` und `BACKUPS/` werden zur Laufzeit erstellt und liegen nicht im Repository.

## Abhängigkeiten (implizit aus Struktur)
- **PHP** für `SCRIPTS/` und `WWW/`.
- **Node.js (>= 18)** für VA/VIDAX.
- **SQLite** als Standardspeicher (Schema in `DB/schema.sql`).
- **ffmpeg** optional für Video-Checks/Thumbs (CLI-Tools referenziert).

## Dokumentation
- **README.md** – Projektüberblick (dieses Dokument).
- **AGENTS.MD** – Arbeitsregeln und Diagnose-Auffälligkeiten.
- **VERSIONSLOG.MD** – rekonstruiertes Funktions-Update-Log.

> Hinweis: Es wurden **keine Tests** ausgeführt.

