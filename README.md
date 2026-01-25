# LocalSupervisor

## Betrieb & Nutzung

### Start/Run
1. **Migrationen:**
   ```bash
   php SCRIPTS/migrate.php
   ```
2. **Worker-Service (Loop):**
   ```bash
   php SCRIPTS/ollama_service_cli.php --sleep-ms=1000 --batch=N --limit=N --max-minutes=N --media-id=ID --max-batches=N
   ```
3. **Enqueue (Ollama-Jobs):**
   ```bash
   php SCRIPTS/ollama_enqueue_cli.php --mode=caption|title|prompt_eval|tags_normalize|quality|prompt_recon|embed|all --limit=N --since=YYYY-MM-DD --all --missing-title --missing-caption
   ```
4. **Dashboard:**
   ```bash
   php -S 127.0.0.1:8080 -t WWW
   ```
   Danach im Browser öffnen: `http://127.0.0.1:8080/dashboard_ollama.php`

### Internal API Endpoints (Loopback + internal_api_key)
`POST /internal_ollama.php`
- **action=status**
- **action=enqueue**
  - `mode` = `caption|title|prompt_eval|tags_normalize|quality|prompt_recon|embed|all`
  - `filters` = `{ limit, since, all, missing_title, missing_caption, media_id, force }`
- **action=run_once**
  - `limit`
- **action=job_status**
  - `job_id`
- **action=cancel**
  - `job_id`
- **action=delete**
  - `media_id`, `mode` = `caption|title|prompt_eval|tags_normalize|quality|prompt_recon|embed|dupe_hints`

### Logpfade
- **Log-Root:** `paths.logs` aus der Config, sonst `LOGS/`
- **Ollama-Worker:** `LOGS/ollama_worker.lock.json`, `LOGS/ollama_worker.err.log`
- **Ollama-Jobs:** `LOGS/ollama_jobs.jsonl`, `LOGS/ollama_errors.jsonl`
- **Ollama-Service:** `LOGS/ollama_service.jsonl`

### Troubleshooting (Minimal)
- **API-Fehler „POST required“ oder „Missing action“:** Request muss `POST` sein und `action` enthalten.
- **„Invalid mode“ / „Invalid job_id“:** Parameter prüfen (Modes und IDs sind strikt validiert).
- **Worker startet nicht:** Prüfe `LOGS/ollama_worker.lock.json` und `LOGS/ollama_worker.err.log` (Lock kann einen zweiten Start blockieren).
- **Migration fehlgeschlagen:** `php SCRIPTS/migrate.php` ausführen, danach Enqueue/Worker erneut starten.
