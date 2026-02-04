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
- Beim Start prüft der Worker die Prompt-Dateien und die Modellverfügbarkeit; fehlende Assets blockieren den Ollama-Worker (globaler Status).
- Web/Internal-Enqueue startet den Ollama-Worker serverseitig automatisch, sofern Jobs anstehen und kein Worker läuft (Launcher-Lock verhindert Doppelstarts).
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

### Ollama Job-States & Retry-Policy
- Zustände: `queued` → `running` → `done/error/cancelled` (`pending` ist legacy und wird nur noch gelesen).
- `running` wird ausschließlich nach einem erfolgreichen Claim/Spawn gesetzt; der Claim setzt `heartbeat_at`.
- Retry-Backoff wird über `jobs.not_before` gesteuert: Jobs bleiben `queued`, bis der Timestamp erreicht ist.
- Timeouts können retrybar sein (solange `max_retries` nicht ausgeschöpft ist).
- Deterministische Vision-Limits werden als `ollama.too_large_for_vision` markiert und bei Vision-Kandidaten übersprungen.
- Backoff-Konfiguration in `CONFIG/config.php` (`ollama.retry`):
  - `backoff_ms` (Basis, Default 1000ms)
  - `backoff_ms_max` (Cap, Default 30000ms)
- Retries sind für transienten Fehler gedacht; harte Fehler enden in `error`.

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
- Der Start wird im Web-Request nicht mehr verifiziert; Status-Polling bestätigt später Worker-Lock/Heartbeat, frischen Worker-Status **oder** geprüften PID (verhindert false negatives bei langsamen Starts).
- `spawn_unverified` kennzeichnet einen ausgelösten Start ohne Verifikation im Request (kein Fehlerstatus).
- Falls `proc_open` nicht verfügbar ist, fällt der Launcher auf `exec` zurück und liefert Diagnosefelder (`spawn_method`, `spawn_ok`, `spawn_status`).
- Unter Windows wird der Worker über PowerShell im Hidden-Window gestartet, damit keine sichtbare Konsole geöffnet wird, inkl. Spawn-Logs und verifizierter PID-Ausgabe.
- Die Windows-Start-Process-Argumente werden als Array übergeben, damit Pfade mit Leerzeichen sauber verarbeitet werden.
- Enqueue (CLI/Web/Internal) stößt einen Autostart an, wenn Jobs pending sind und kein verifizierter Worker läuft; der Launcher-Lock enthält `last_spawn_at` (Cooldown via `ollama.spawn_cooldown`).
- Autostart setzt keine Batch-Abbrüche; der Worker läuft, bis die Queue stabil leer ist (auch wenn zwischenzeitlich neue Jobs reinkommen).
- Spawn-Status wird in `ollama_worker_spawn.last.json` protokolliert und kann im Status-Endpoint eingesehen werden.
- Delete-Action unterstützt `force=1`, um laufende Jobs zu canceln und zu entfernen.
- Trace-Dateien enthalten `stage_history` (Zeit + Dauer je Stage) und werden bei Fehlern/Cancels finalisiert.
- Der Worker schreibt `worker_active` in `LOGS/ollama_status.json` (Heartbeat), damit Start/Status verlässlich geprüft werden kann.
- Runner/Spawn-Antworten liefern ein einheitliches Statusschema mit `status` (started|running|locked|busy|start_failed|open_failed|config_failed) und `reason_code` (z. B. `spawn_unverified`, `lock_busy`, `log_root_unavailable`), damit UI/CLI zwischen Lock, IO/Path und Spawn-Fehlern eindeutig unterscheiden.
- Worker-„running“ wird zentral über Lock/Heartbeat geprüft (`is_ollama_worker_running`): Launcher-Locks oder `web:*`-Owner zählen nicht, und Locks mit `php_server.pid` gelten als ungültig.

### Logpfade
- **Log-Root:** `paths.logs` aus der Config, sonst `LOGS/`
- **Ollama-Worker:** `LOGS/ollama_worker.lock`, `LOGS/ollama_worker.out.log`, `LOGS/ollama_worker.err.log`
- **Ollama-Launcher:** `LOGS/ollama_launcher.lock`
- **Ollama-Jobs:** `LOGS/ollama_jobs.jsonl`, `LOGS/ollama_errors.jsonl`
- **Ollama-Service:** `LOGS/ollama_service.jsonl`
- **Ollama-Status:** `LOGS/ollama_status.json`
- **Worker-Locks:** `LOGS/scan_worker.lock.json`, `LOGS/forge_worker.lock.json`, `LOGS/library_rename_worker.lock.json`
- **Spawn-Logs:** `LOGS/scan_worker_spawn.*`, `LOGS/forge_worker_spawn.*`, `LOGS/ollama_worker_spawn.last.json`
- **System-Fehlerlog:** `LOGS/system_errors.jsonl` (kritische IO/Spawn-Fehler)

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
- Lokale Loopback-Aufrufe interner Aktionen liefern konsistente `status`/`reason_code` und können bei fehlendem Key per Localhost-Bypass freigeschaltet werden (nur Loopback).

## Minimal Tests (manuell, nicht automatisch)
1. **Ollama down:** Runner starten → keine Jobs werden `running`, Log enthält `ollama_down`.
2. **Vision-Job groß:** Worker startet Child; nach `max_seconds` wird gekillt → Job endet `error/timeout`, Delete funktioniert.
3. **tags_normalize:** Bei `connection refused` bleibt der Trace final mit Fehlerdaten gefüllt.

## Troubleshooting (kurz)
- **SQLite busy_timeout/WAL:** Werte in `CONFIG/config.php` prüfen (`db.sqlite`).
- **Jobs hängen/Queue voll:** `jobs_admin.php` und `jobs_prune.php` nutzen.
- **Scanner/Ollama/Forge nicht erreichbar:** `base_url` in der Config prüfen.

## Ollama – typische Fehlerursachen (Trigger-Klassen)
Die folgenden Trigger helfen beim schnellen Einordnen von „Jobs hängen“ oder „Queue idle“ im Ollama-Modul. Sie sind bewusst als Ursachenklassen gruppiert, damit Logs, Status und Recovery zielgerichtet geprüft werden können.

### Runner/Worker-Start (Lock, Spawn, Heartbeat)
- **Runner nicht startbar (Lock-Kollision):** `ollama_jobs.php` nutzt `flock` für den Worker. Wenn Web + CLI gleichzeitig starten, kann ein Lock kollidieren → Symptom: „started“ wirkt erfolgreich, aber Queue bleibt idle. Ergänzung: Stale Lock durch Crash blockiert alle Jobs; Lock/Status laut README/Logs manuell clearen.
- **No Child-Spawn:** `proc_open` schlägt fehl (z. B. Prompt fehlt) → Worker bleibt als „running“-Zombie hängen. Symptom: Hänger; Watchdog requeued nach ~10 min.

### Ollama-HTTP & Payload
- **Ollama-HTTP bricht:** `ollama_client.php` retried (3x), aber bei „down“ oder fehlerhaftem Payload (z. B. Modell doppelt im Options-Block) endet es in `ollama_down`. Symptom: `blocked_by_ollama`, Queue eingefroren. Ergänzung: kein Model-Preload-Check vor Enqueue.

### Input-Validierung & Media-Pipeline
- **Input invalid:** `ollama_enqueue_cli.php` lässt Non-Images durch, `ollama_jobs.php` wirft `invalid_image`. Symptom: Retry-Loops (da nicht `too_large`) → Jobs hängen. Bestätigt: kein Type-Filter im Enqueue.
- **Zeitbudget/Timeout:** `ollama_client.php` killt nach 180s; `ollama_jobs.php` requeued. Symptom: Stale-Requeues auf langsamer HW. Ergänzung: kein Chunking großer Dateien.

### Parsing & Validierung
- **Parse-Fehler:** Strenge JSON-Validierung in `ollama_jobs.php`. Symptom: Requeue bei non-JSON. Ergänzung: Determinismus (seed=42) hilft, aber Modellvarianten triggern weiter.

### Ergänzende Trigger (Konfig & Betrieb)
- **DB-Locks:** SQLite WAL/Locks (README). 
- **Concurrency-Cap:** `jobs`-Limit blockiert (z. B. 2 running). 
- **Konfig-Fehler:** falsches `prompts_dir` oder `base_url` (README prüfen).

## Ollama – Sofortmaßnahmen bei „Jobs hängen“ (Praxis-Checkliste)
Wenn der Status **„Worker-Start nicht verifiziert (kein Lock/Heartbeat)“** erscheint, liegt meist ein fehlender/gebrochener Worker-Start oder ein stale Lock vor. Nutze die folgenden Schritte, um Hänger dauerhaft zu vermeiden:

### 1) Lock/Heartbeat prüfen & zurücksetzen
- **Lock-Dateien prüfen:** `LOGS/ollama_worker.lock` und `LOGS/ollama_launcher.lock`.
- **Stale Lock entfernen:** falls kein aktiver Worker läuft, Lock-Dateien manuell löschen (README/Logs als Referenz).
- **Heartbeat in DB prüfen:** `jobs.heartbeat_at` muss sich bei laufendem Worker bewegen.

### 2) Worker-Start validieren (ohne Web-Loop)
- **CLI-Worker direkt starten:** `php SCRIPTS/ollama_service_cli.php --sleep-ms=1000 --batch=5 --limit=20`
- **Lock erst im Worker setzen:** der Web-Launcher bestätigt erst nach erfolgreichem Lock/Heartbeat (kein „fake started“).

### 3) Ollama-HTTP & Modell-Check
- **Ollama erreichbar?** Prüfe `base_url` und `/api/tags` (Model-Preload).
- **Payload bereinigen:** Model nur im Top-Level, Options ohne Nulls/dup.
- **Bei `ollama_down`:** längeres Backoff, ggf. Auto-Start/Healthcheck nutzen.

### 4) Input-Filtern & Retry-Loops stoppen
- **Non-Images aus Enqueue filtern:** Vision-Modi nur `type='image'`.
- **Timeouts vermeiden:** große Dateien vorab downscalen oder chunking einsetzen.
- **Invalid-Inputs:** `invalid_image` nicht endlos requeuen → als Fehler klassifizieren.

### 5) Parsing robuster machen
- **Tolerantes Parsing:** Raw Output speichern, Partial-Extract (degraded statt fail).
- **Parse-Fehler begrenzen:** Retry nur 1x, danach Fehlerstatus mit Trace.

### 6) Recovery/Monitoring
- **Queue entlasten:** `jobs_admin.php` / `jobs_prune.php`.
- **Fehler klassifizieren:** `spawn_fail`, `timeout`, `http_400`, `ollama_down` in Logs/DB.
- **Alerts:** Warnung bei „Queue idle“ oder „no heartbeat“.

## Ollama – Resümee & Verbesserungsansatz (ausführlich)
Das Ollama-Modul ist stabil, aber anfällig für Hänger. Folgende Maßnahmen transformieren es in ein resilienteres, skalierbares System, ohne die Modularität zu verlieren.

### Leitprinzipien
- **Atomare State-Machine:** Status nur zentral setzen (`ollama_jobs.php`), klarer Ablauf: `queued → running → done/error`. `pending` nur transient/legacy.
- **Lease-Locks & Heartbeat:** Claims mit Owner-PID, Timestamp und `heartbeat_at`. Stale-Locks automatisch freigeben.
- **Einheitliche Retry-Policy:** `max_retries`, `not_before` für Backoff, Fehlerklassen (transient vs. permanent).
- **Tolerantes Parsing:** Roh-Output speichern, Partial-Extract (degraded statt fail) bei fehlenden Keys.
- **Adaptive Limits:** Timeout/Chunking modell- und hardwareabhängig.

### Technische Stabilisierung (Code/Workflow)
- **Runner-Start:** Lock nur im Worker, nicht im Web-Loop. Service prüft Heartbeat statt „started“-Flag.
- **Ollama-Client:** Payload bereinigen (Model nur Top-Level, Options ohne Nulls). Pre-Check via `/api/tags` vor Enqueue.
- **Input-Pipeline:** `ollama_enqueue_cli.php` filtert Non-Images je Mode; Videos per Pre-Frame-Extract oder Skip.
- **Parse-Validation:** JSON-Level (Syntax → Keys → Ranges), Degraded-Flag statt Retry-Loop.
- **Retry-Backoff:** Exponentiell (1s → 30s), `ollama_down` mit längerer Pause.

### Operationalisierung (Monitoring/Debug)
- **Fehlerklassifikation:** `spawn_fail`, `timeout`, `http_400`, `ollama_down` usw. in Logs/DB konsistent.
- **Observability:** Job-Korrelation (Job-ID, Attempt), zentrale Logs (z. B. Monolog/ELK), Trace-Viewer.
- **Alerts/Dashboard:** WebSocket-Status, Ursachen gruppieren, Warnungen für „Queue idle“.

### Usability & Skalierung
- **Concurrency-Fenster:** Dynamisch nach HW, limitierte parallele Vision-Jobs.
- **Chunking großer Inputs:** Adaptive Downscale/Compress vor Vision.
- **Fallbacks:** Optionaler Cloud-Fallback, wenn Ollama down ist (konfigurierbar).

### Ergebnis (Soll-Zustand)
Zuverlässiger Start ohne Zombie-Running, valide Eingaben, konsistente Payloads, degradiertes Parsing statt Requeue, endliche Retries und klarer Recovery-Flow. Das reduziert Frust bei großen Libraries und erlaubt sichere Skalierung.

## Verweise
- `VERSIONLOG.MD`
- `CONFIG/config.example.php`
