# Supervisor (Repo: LocalSupervisor)

Supervisor ist ein lokales System zum Erfassen, Verwalten und Auswerten großer Medienbibliotheken (Bilder/Videos). Die Anwendung arbeitet auf einer lokalen Datenbank, kann Scanner- und Job-Worker einbinden und stellt eine Web-UI für Listing, Detailansicht und Streaming bereit. Das Repository heißt „LocalSupervisor“, der Produktname ist „Supervisor“.

## Kernfunktionen
- Medien-DB für Bilder/Videos inkl. Hashing, Metadaten, Rating/NSFW, Tags, Prompts und Collections.
- Scanner-Integration mit Scan-Ergebnissen (scan_core/scan_results) und Import-Workflow.
- Job/Queue-System (jobs) mit Worker-Runnern für Scan, Forge und Ollama.
- Web UI für Listing, Detailansicht, Thumbnails und Streaming.
- Forge-Anbindung für Regeneration/Rezepte/Worker.
- Ollama-Anreicherung als Modul (Caption/Title/Prompt-Eval/Tags Normalize/Quality/Prompt Recon/Embeddings/Dupe Hints/NSFW).
- Audit/Consistency/Import-Logging (audit_log, consistency_log, import_log).

## Projektstruktur
- `WWW/`: Web-UI und interne Endpoints (mediadb.php, media_view.php, dashboard_ollama.php, internal_ollama.php, health.php, jobs_prune.php).
- `SCRIPTS/`: Runner/CLI (migrate.php, init_db.php, db_status.php, scan_path_cli.php, scan_worker_cli.php, rescan_cli.php, prompts_rebuild_cli.php, forge_worker_cli.php, forge_recipes.php, ollama_enqueue_cli.php, ollama_service_cli.php, jobs_admin.php, cleanup_missing_cli.php, consistency_check.php, selftest_cli.php).
- `DB/`: Datenbank, Schema und Migrationen.
- `CONFIG/`: Konfigurationsvorlagen (`config.example.php`).
- `PROMPTS/ollama/`: dateibasierte Prompt-Templates (caption/title/prompt_eval/tags_normalize/quality/prompt_recon/nsfw_classify).
- `LIBRARY/`, `CACHE/`, `PREVIEWS/`: lokale Medien-/Cache-/Preview-Pfade (in der Config referenziert).
- `src/vidax/`, `bin/va.js`: Node-Komponente (VIDAX-Server + CLI-Utilities).

## Quickstart
1. **CONFIG**
   ```bash
   cp CONFIG/config.example.php CONFIG/config.php
   ```
   Pfade in `CONFIG/config.php` prüfen/anpassen.
2. **DB**
   ```bash
   php SCRIPTS/init_db.php
   php SCRIPTS/migrate.php
   ```
3. **Web**
   ```bash
   php -S 127.0.0.1:8080 -t WWW
   ```
4. **Worker (Beispiele)**
   ```bash
   php SCRIPTS/scan_worker_cli.php --limit=50
   php SCRIPTS/forge_worker_cli.php --limit=10
   php SCRIPTS/ollama_service_cli.php --sleep-ms=1000 --batch=5 --limit=20
   ```

## Workflows

### Import/Scan eines Ordners
- Ordner in den Library-Pfaden vorbereiten (CONFIG → `paths`).
- Scan-Job anlegen:
  ```bash
  php SCRIPTS/scan_path_cli.php --path="/Pfad/zu/import" --source=other
  ```
- Scan-Worker ausführen (Batch/Limit steuern):
  ```bash
  php SCRIPTS/scan_worker_cli.php --limit=50
  ```
- Ergebnisse landen in `media`, `scan_results`, `import_log`.
- Status in der Web-UI (mediadb.php) prüfen.

### Rescan & Prompt-Rebuild
- Rescan anstoßen (z. B. Metadaten neu einlesen):
  ```bash
  php SCRIPTS/rescan_cli.php --limit=100
  ```
- Prompt-Spalten aus Quellen neu aufbauen:
  ```bash
  php SCRIPTS/prompts_rebuild_cli.php --limit=100
  ```
- Änderungen erscheinen in `prompts` und `prompt_history`.
- Bei Fehlern `consistency_check.php` für eine Prüfung nutzen.

### Forge Regeneration
- Forge-Rezepte stammen aus `SCRIPTS/forge_recipes.php` (oder `CONFIG/forge_recipes.json`).
- Regenerations-Jobs erzeugen Job-Queue-Einträge (`jobs` / `forge_*`).
- Worker verarbeitet Jobs und schreibt `forge_request_json` / `forge_response_json`.
- Laufzeit-Infos und Audit-Einträge landen in Logs und `audit_log`.
- Kontrolle über `jobs_admin.php` und `jobs_prune.php`.

### Ollama Enqueue + Service Loop
- Prompt-Templates kommen aus `PROMPTS/ollama/*.txt`.
- Jobs anlegen:
  ```bash
  php SCRIPTS/ollama_enqueue_cli.php --mode=caption|title|prompt_eval|tags_normalize|quality|nsfw_classify|prompt_recon|embed|all --limit=N --since=YYYY-MM-DD --all --missing-title --missing-caption
  ```
- Worker-Service starten:
  ```bash
  php SCRIPTS/ollama_service_cli.php --sleep-ms=1000 --batch=N --limit=N --max-minutes=N --media-id=ID --max-batches=N
  ```
- Ergebnisse werden in `ollama_results` und `media_meta` abgelegt.
- Status und Fehler im Ollama-Dashboard prüfen (siehe Web-UI).

## Ollama (CLI, Internal API, Logs)

### Internal API Endpoints (Loopback + internal_api_key)
`POST /internal_ollama.php`
- **action=status**
- **action=enqueue**
  - `mode` = `caption|title|prompt_eval|tags_normalize|quality|nsfw_classify|prompt_recon|embed|all`
  - `filters` = `{ limit, since, all, missing_title, missing_caption, media_id, force }`
- **action=run_once**
  - `limit`
- **action=job_status**
  - `job_id`
- **action=cancel**
  - `job_id`
- **action=delete**
  - `media_id`, `mode` = `caption|title|prompt_eval|tags_normalize|quality|nsfw_classify|prompt_recon|embed|dupe_hints`

### Runner/Tracing Hinweise
- `WWW/ollama.php?action=run` startet jetzt den CLI-Worker und gibt sofort zurück (kein Web-Prozess-Blocking).
- Unter Windows wird der Worker über PowerShell im Hidden-Window gestartet, damit keine sichtbare Konsole geöffnet wird.
- Die Windows-Start-Process-Argumente sind gequotet, damit Pfade mit Leerzeichen sauber verarbeitet werden.
- Delete-Action unterstützt `force=1`, um laufende Jobs zu canceln und zu entfernen.
- Trace-Dateien enthalten `stage_history` (Zeit + Dauer je Stage) und werden bei Fehlern/Cancels finalisiert.

### Logpfade
- **Log-Root:** `paths.logs` aus der Config, sonst `LOGS/`
- **Ollama-Worker:** `LOGS/ollama_worker.lock.json`, `LOGS/ollama_worker.err.log`
- **Ollama-Jobs:** `LOGS/ollama_jobs.jsonl`, `LOGS/ollama_errors.jsonl`
- **Ollama-Service:** `LOGS/ollama_service.jsonl`

## Web UI Seiten
- `mediadb.php`: Listing/Filter der Medien.
- `media_view.php`: Detailansicht (Metadaten, Tags, Prompts, Assets).
- `dashboard_ollama.php`: Ollama-Übersicht (nur Loopback/Intern).
- `internal_ollama.php`: interner API-Endpoint (nur Loopback/Intern).
- `health.php`: Healthcheck.
- `jobs_prune.php`: Jobs-Bereinigung.
- Featureview in `mediadb.php` zeigt maximal 4 Einträge mit der niedrigsten Aktivität.

## Security Modell
- Interne Endpoints erfordern `internal_api_key` und Loopback-Zugriff.
- Whitelist-Validierung über `ip_whitelist`.
- Session/Cookie-Handling hängt von `allow_insecure_internal_cookie` ab.
- Default ist lokal/loopback-orientiert.

## Minimal Tests (manuell, nicht automatisch)
1. **Ollama down:** Runner starten → keine Jobs werden `running`, Log enthält `ollama_down`.
2. **Vision-Job groß:** Worker startet Child; nach `max_seconds` wird gekillt → Job endet `error/timeout`, Delete funktioniert.
3. **tags_normalize:** Bei `connection refused` bleibt der Trace final mit Fehlerdaten gefüllt.

## Troubleshooting (kurz)
- **SQLite busy_timeout/WAL:** Werte in `CONFIG/config.php` prüfen (`db.sqlite`).
- **Jobs hängen/Queue voll:** `jobs_admin.php` und `jobs_prune.php` nutzen.
- **Scanner/Ollama/Forge nicht erreichbar:** `base_url` in der Config prüfen.

## Verweise
- `VERSIONLOG.MD`
- `CONFIG/config.example.php`
