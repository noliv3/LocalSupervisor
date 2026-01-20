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

## Start-Workflow (start.ps1)
- Start.ps1 protokolliert Start/Stop des PHP-Servers inkl. PID/Command/CWD und räumt `php_server.pid` beim Beenden zuverlässig auf.
- Exit-Hooks nutzen die konfigurierten Log-Pfade (Fallback `LOGS/`) und stoppen verbliebene `php.exe`-Prozesse.

## Zugriff & Sicherheit (Web)
- **Public (Remote):** Galerie/Detailansicht lesen, Metadaten sehen und `vote_up` via POST; keine Admin-/Scan-/Rescan-/Checked-/Downvote-Aktionen.
- **FSK18-Inhalte:** Sichtbarkeit/Stream/Thumb nur bei internem Zugriff; Public kann `?adult=1` nicht erzwingen.
- **Intern (lokal):** Vollzugriff nur über Loopback (`127.0.0.1/::1/::ffff:127.0.0.1`) **und** `internal_api_key`; Dashboard und interne Aktionen sind geschützt.
- **Key-Storage:** Session/Cookie für `internal_key` wird nur für Loopback-Requests gesetzt.

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
