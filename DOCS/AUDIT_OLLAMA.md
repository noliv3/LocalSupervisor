# Repository-Audit – Vorbereitung Ollama-Modul

> Ziel: Bestandsaufnahme der vorhandenen Medien-, DB-, Scanner-, Prompt-, Job- und Repair-Flows als Grundlage für das Ollama-Modul (keine Implementierung).

## 1) Module & Ordner mit Bezug zu Medien/Bildern

### Medien- und Datei-Handling
- `DB/schema.sql` – zentrale Medientabelle (`media`) inkl. Pfad, Typ, Hash, NSFW, Rating, Quality, Lifecycle-Status; Basis für jede Bildanalyse/Anreicherung.【DB/schema.sql】
- `SCRIPTS/scan_core.php` – Import/Rescan-Logik für Medien, Tagging und NSFW-Auswertung; schreibt Tags/Scan-Ergebnisse.【SCRIPTS/scan_core.php】
- `WWW/media_stream.php` – Stream-Endpoint für Medien und Job-Assets (Preview/Backup/Output) für UI/Download.【WWW/media_stream.php】
- `WWW/thumb.php` – Thumbnail-Endpoint für Bilder/Videos inkl. ffmpeg-Preview bei Videos.【WWW/thumb.php】
- `WWW/media_view.php` – Detailansicht inkl. Tag-/Prompt-Infos, Actions und Issue-Displays.【WWW/media_view.php】
- `WWW/mediadb.php` – Galerie/Listenansicht, Filter für Issues, Prompt-Qualität, Rescan-Aktionen.【WWW/mediadb.php】

### Datenbankzugriff / Status / Inspektion
- `SCRIPTS/db_helpers.php` – zentrale DB-Helper (Verbindung, Schema-Abgleich, Migrationen).【SCRIPTS/db_helpers.php】
- `SCRIPTS/db_status.php` – CLI-Status inkl. Schema-/Migrationsprüfung.【SCRIPTS/db_status.php】
- `SCRIPTS/db_inspect.php` – CLI-Statistik/Counts + Sample-Listen (media/tags/scan_results).【SCRIPTS/db_inspect.php】
- `SCRIPTS/meta_inspect.php` – CLI-Inspektion von Prompt- und Meta-Werten pro Media-ID.【SCRIPTS/meta_inspect.php】

### Scanner / NSFW / Tagging
- `SCRIPTS/scan_core.php` – Scanner-Response-Parsing, Tag-Normalisierung, NSFW-Score-Handling, Tag-Writeback, Logs.【SCRIPTS/scan_core.php】
- `DB/schema.sql` – `scan_results` (NSFW-Score, Flags, Raw JSON) und `tags`/`media_tags` für Tagging-Speicher.【DB/schema.sql】
- `CONFIG/config.example.php` – Scanner-Konfiguration (base_url, timeout, nsfw_threshold).【CONFIG/config.example.php】

### Prompt-Bewertung / Ratings
- `DB/schema.sql` – `prompts` & `prompt_history` für Prompt-Source, Versionierung, Metadaten.【DB/schema.sql】
- `SCRIPTS/prompt_parser.php` – Normalisierung & Parsing von Prompt-Text/Metadaten.【SCRIPTS/prompt_parser.php】
- `SCRIPTS/operations.php` – Prompt-Qualitätsbewertung (A/B/C) + Issues/Score-Logik.【SCRIPTS/operations.php】
- `WWW/media_view.php` & `WWW/mediadb.php` – Anzeige und Filterung der Prompt-Qualität im UI.【WWW/media_view.php】【WWW/mediadb.php】

### Jobs / Queue / Batch-Verarbeitung
- `DB/schema.sql` – `jobs`-Tabelle (Status, Payload, Fehler).【DB/schema.sql】
- `SCRIPTS/operations.php` – Queue-Limits, Job-Status-Handling, Batch-Processing für Scan/Forge u. a.【SCRIPTS/operations.php】
- `SCRIPTS/scan_worker_cli.php` – Batch-Worker für Scan/Rescan/Backfill-Jobs (CLI).【SCRIPTS/scan_worker_cli.php】
- `SCRIPTS/ollama_jobs.php` – Ollama-Job-Handling (Stage 1: Caption/Title, Meta-Writeback).【SCRIPTS/ollama_jobs.php】
- `SCRIPTS/ollama_worker_cli.php` – Ollama-Job-Worker (CLI).【SCRIPTS/ollama_worker_cli.php】
- `SCRIPTS/ollama_enqueue_cli.php` – Ollama-Job-Enqueue (CLI).【SCRIPTS/ollama_enqueue_cli.php】

### Reprocess / Rescan / Repair
- `SCRIPTS/rescan_cli.php` – Rescan-CLI für Medien-Tags/NSFW erneuern.【SCRIPTS/rescan_cli.php】
- `SCRIPTS/consistency_check.php` – Konsistenzprüfung mit optionalem Simple-Repair-Mode (CLI).【SCRIPTS/consistency_check.php】
- `SCRIPTS/operations.php` – Forge-Repair/Regeneration-Queueing & Ausführung.【SCRIPTS/operations.php】
- `WWW/media_view.php` – Repair-UI und Operator-Actions im Detail-View.【WWW/media_view.php】

### Export / Statistik / Diagnose
- `SCRIPTS/db_status.php` – Schema-/Migrationsstatus als Diagnose-Output.【SCRIPTS/db_status.php】
- `SCRIPTS/db_inspect.php` – Tabellen-Counts + Beispiel-Daten als Quick-Statistik.【SCRIPTS/db_inspect.php】
- `SCRIPTS/meta_inspect.php` – Prompt-/Meta-Inspektion für manuelles Audit.【SCRIPTS/meta_inspect.php】

## 2) Entry-Points (CLI / PowerShell / Web / Node)

### CLI (PHP)
- `SCRIPTS/scan_path_cli.php` – Pfad-basiertes Scanning (Batch).【SCRIPTS/scan_path_cli.php】
- `SCRIPTS/scan_worker_cli.php` – Scan/Rescan/Backfill-Worker (Batch).【SCRIPTS/scan_worker_cli.php】
- `SCRIPTS/rescan_cli.php` – Rescan-Runner (Batch).【SCRIPTS/rescan_cli.php】
- `SCRIPTS/consistency_check.php` – Konsistenzprüfung/Repair (Batch).【SCRIPTS/consistency_check.php】
- `SCRIPTS/filesync_cli.php` – Filesync-Operationen (Batch).【SCRIPTS/filesync_cli.php】
- `SCRIPTS/db_status.php` / `SCRIPTS/db_inspect.php` / `SCRIPTS/meta_inspect.php` – DB/Meta-Inspection.【SCRIPTS/db_status.php】【SCRIPTS/db_inspect.php】【SCRIPTS/meta_inspect.php】
- `SCRIPTS/ollama_enqueue_cli.php` / `SCRIPTS/ollama_worker_cli.php` – Ollama Stage-1 CLI (Caption/Title).【SCRIPTS/ollama_enqueue_cli.php】【SCRIPTS/ollama_worker_cli.php】
- `SCRIPTS/forge_worker_cli.php` – Forge-Job-Worker (Batch).【SCRIPTS/forge_worker_cli.php】
- `SCRIPTS/exif_prompts_cli.php` – Legacy-EXIF/Prompt-Extraction (Batch).【SCRIPTS/exif_prompts_cli.php】

### Web (PHP)
- `WWW/index.php` – Dashboard/Health/Job-Center (UI).【WWW/index.php】
- `WWW/mediadb.php` – Galerie/Medienliste, Filter & Aktionen (UI/API).【WWW/mediadb.php】
- `WWW/media_view.php` – Detailansicht, Prompt-/Tag-UI, Repair/Actions.【WWW/media_view.php】
- `WWW/media_stream.php` – Stream/Download für Assets.【WWW/media_stream.php】
- `WWW/thumb.php` – Thumbnail-Endpoint (Bilder/Videos).【WWW/thumb.php】

### PowerShell
- `start.ps1` – Start/Stop/Update-Entry-Point (Windows).【start.ps1】

### Node
- `bin/va.js` – VA CLI (Doctor/Install).【bin/va.js】
- `src/vidax/server.js` – VIDAX-Server (Express).【src/vidax/server.js】
- `package.json` – Node Scripts (`start:vidax`, `va:doctor`, `va:install`).【package.json】

## 3) Fehlende oder unvollständige Felder (Status)

### Titel
- **Kein eigenes DB-Feld** für einen Medien-Titel in `media` oder `prompts`; nur generisches `media_meta` ist verfügbar.【DB/schema.sql】
- **Konsequenz:** Titel müsste als `media_meta`-Key (z. B. `ollama.title`) oder neues Feld konzeptioniert werden.【DB/schema.sql】

### Beschreibung
- **Kein dediziertes Feld** für eine generierte Beschreibung in `media`/`prompts`; nur `media_meta` als Schlüssel-Wert-Speicher vorhanden.【DB/schema.sql】

### Bildqualität
- Es existieren `quality_status`, `quality_score`, `quality_notes` im `media`-Datensatz, aber keine dedizierten Felder für **Ollama-spezifische Qualitäts-Analysen** (z. B. Modell-/Prompt-Version, Vision-spezifische Flags).【DB/schema.sql】

### Prompt-Abgleich / Prompt-Match-Score
- **Kein Feld** für Prompt-Match-Score oder Widerspruchsmarker; Prompt-Qualität (A/B/C) wird heuristisch bewertet und nicht als Vergleich mit Bildinhalt gespeichert.【SCRIPTS/operations.php】【DB/schema.sql】

### Duplikaterkennung
- Einziges persistentes Duplikat-Signal ist der `media.hash`-Index; kein explizites Feld für `duplicate_suspect` oder Duplikat-Cluster vorhanden.【DB/schema.sql】
- Konsistenzprüfung erkennt Hash-Duplikate, erzeugt aber keinen persistierten Flag-Status im Media-Datensatz.【SCRIPTS/operations.php】

### Fehlerstatus (Analysefehler/Ollama)
- Jobs speichern `error_message`, aber es gibt **kein Media-Feld** für letzte Analyse-Fehler oder dauerhafte Error-Flags auf Medienebene.【DB/schema.sql】
- Scanner-Errors liegen in `scan_results.raw_json` und Logs, nicht als strukturiertes Fehlerfeld im `media`-Datensatz.【DB/schema.sql】【SCRIPTS/scan_core.php】

## 4) Relevante Ollama-Stellen im Bestand
- `SCRIPTS/ollama_client.php` – Konfiguration, Request-Formate und Healthcheck zu Ollama API.【SCRIPTS/ollama_client.php】
- `SCRIPTS/ollama_prompts.php` – Stage-1 Prompt-Definitionen (Caption/Title).【SCRIPTS/ollama_prompts.php】
- `SCRIPTS/ollama_jobs.php` – Job-Handling, Meta-Writeback (`ollama.caption`, `ollama.title`).【SCRIPTS/ollama_jobs.php】
- `CONFIG/config.example.php` – Ollama-Konfig (Modelle, Timeout, Determinismus, Max-Image-Bytes).【CONFIG/config.example.php】

---

**Hinweis:** Dieses Audit liefert die Referenzen für die nachfolgenden Spezifikationen (Interfaces, Datenverträge, Pipeline). Es werden keine Änderungen am Produktivcode vorgenommen.
