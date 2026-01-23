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
- **Ollama (CLI):**
  - Enqueue: `php SCRIPTS/ollama_enqueue_cli.php --mode=caption|title|prompt_eval|all --all --missing-title --missing-caption --since=YYYY-MM-DD --limit=N`
  - Worker: `php SCRIPTS/ollama_worker_cli.php --limit=N --max-batches=N`
  - Smoke-Test: `php SCRIPTS/ollama_smoke.php --media-id=123`
  - Jobs werden initial mit Status `queued` erstellt; der Worker verarbeitet `queued` und `pending` und schreibt bei Erfolg Meta-Keys wie `ollama.caption`, `ollama.title`, `ollama.prompt_eval.score` sowie `ollama.<mode>.meta`.

## Start-Workflow (start.ps1)
- Start.ps1 protokolliert Start/Stop des PHP-Servers inkl. PID/Command/CWD und räumt `php_server.pid` beim Beenden zuverlässig auf.
- Exit-Hook prüft die PID-Datei unter `LOGS/` sowie optional im konfigurierten Log-Dir, validiert den Prozessnamen (`php`) und entfernt die PID-Datei danach.

## Zugriff & Sicherheit (Web)
- **Public (Remote):** Galerie/Detailansicht lesen, Metadaten sehen und `vote_up` via POST; keine Admin-/Scan-/Rescan-/Checked-/Downvote-Aktionen.
- **FSK18-Inhalte:** Sichtbarkeit/Stream/Thumb nur bei internem Zugriff; Public kann `?adult=1` nicht erzwingen.
- **Intern (lokal):** Vollzugriff nur über Loopback (`127.0.0.1/::1/::ffff:127.0.0.1`) **und** `internal_api_key`; Dashboard und interne Aktionen sind geschützt.
- **Key-Storage:** `internal_key` wird nur bei HTTPS oder mit `security.allow_insecure_internal_cookie=true` persistiert; bei HTTP ist der Key pro Request im Header erforderlich.
- **Interne Ollama-API:** `POST /internal_ollama.php` (actions: `enqueue`, `status`, `run_once`) ist strikt intern (Loopback + `internal_api_key`).

## Job-Queue-Guards (Konfiguration)
- Limits für Queue-Größe können über `jobs.queue_max_total`, `jobs.queue_max_per_type_default`, `jobs.queue_max_per_type` und `jobs.queue_max_per_media` gesetzt werden.
- Überschreitungen führen zu einer Ablehnung weiterer Enqueue-Versuche, damit keine Endlosschleifen entstehen.

## SQLite-Tuning (optional)
- `db.sqlite.busy_timeout_ms` und `db.sqlite.journal_mode` steuern Sperr-/WAL-Verhalten für SQLite.

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
- **DOCS/AUDIT_OLLAMA.md** – Ist-Zustand/Audit für die geplante Ollama-Integration.
- **DOCS/INTERFACES_OLLAMA.md** – Schnittstellenliste für das Ollama-Modul.
- **DOCS/DATA_CONTRACTS_OLLAMA.md** – JSON-Schemas für Ollama-Requests/Responses/DTOs.
- **DOCS/pipeline_ollama.md** – Stufenplan für die Ollama-Pipeline (MVP → Erweiterungen).
- **DOCS/agents_ollama.md** – Agenten-Dokumentation inkl. Prompt/Logging-Regeln.
- **Ollama Stage 1 (CLI):** `SCRIPTS/ollama_enqueue_cli.php`, `SCRIPTS/ollama_worker_cli.php` (Caption/Title).
- **Ollama Stage 2 (CLI/API):** `SCRIPTS/ollama_smoke.php` + `WWW/internal_ollama.php` + `ollama_results`-Pipeline.

> Hinweis: Es wurden **keine Tests** ausgeführt.
