# Supervisor (Repo: LocalSupervisor)

Supervisor ist ein lokales System zum Erfassen, Verwalten und Auswerten großer Medienbibliotheken (Bilder/Videos). Die Anwendung arbeitet auf einer lokalen Datenbank, kann Scanner- und Job-Worker einbinden und stellt eine Web-UI für Listing, Detailansicht und Streaming bereit. Das Repository heißt „LocalSupervisor“, der Produktname ist „Supervisor“.

## Kernfunktionen
- Medien-DB für Bilder/Videos inkl. Hashing, Metadaten, Rating/NSFW, Tags, Prompts und Collections.
- Scanner-Integration mit Scan-Ergebnissen (scan_core/scan_results) und Import-Workflow.
- Job/Queue-System (jobs) mit Worker-Runnern für Scan, Forge und Ollama.
- Additive Medien-Jobs für Integrity-Checks, SHA256-Hashing und Upscale-Derivate (Parent → Child).
- Web UI für Listing, Detailansicht, Thumbnails und Streaming.
- Forge-Anbindung für Regeneration/Rezepte/Worker.
- Ollama-Anreicherung als Modul (Caption/Title/Prompt-Eval/Tags Normalize/Quality/Prompt Recon/Embeddings/Dupe Hints/NSFW).
- Audit/Consistency/Import-Logging (audit_log, consistency_log, import_log).

## Projektstruktur
- `WWW/`: Web-UI und interne Endpoints (mediadb.php, media_view.php, dashboard_ollama.php, internal_ollama.php, health.php, jobs_prune.php).
- `SCRIPTS/`: Runner/CLI (migrate.php, init_db.php, db_status.php, scan_path_cli.php, scan_worker_cli.php, rescan_cli.php, prompts_rebuild_cli.php, forge_worker_cli.php, forge_recipes.php, ollama_enqueue_cli.php, ollama_service_cli.php, jobs_admin.php, cleanup_missing_cli.php, consistency_check.php, selftest_cli.php).
- `SCRIPTS/media_worker_cli.php`: Worker für Integrity-Check-, SHA256- und Upscale-Jobs.
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
   Pfade in `CONFIG/config.php` prüfen/anpassen (inkl. `paths.derivatives` für Upscale-Derivate).
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
   php SCRIPTS/media_worker_cli.php --limit=50
   php SCRIPTS/ollama_service_cli.php --sleep-ms=1000 --batch=5 --limit=20
   ```

## Workflows

### Funktionscheck: Worker-Übergabe & stabile Ausführung
- **Einheitliches Claiming:** Alle Queue-Worker ziehen Jobs aus `jobs` und setzen den Status atomar auf `running` (entweder über `sv_claim_job_running` oder via Ollama-Claim-Funktion), damit keine Doppelverarbeitung entsteht.
- **Stuck-Recovery vor jeder Batch:** Scan-, Forge-, Media-, Library-Rename- und Ollama-Worker markieren/überführen alte `running`-Jobs über `sv_recover_stuck_jobs` bzw. `sv_mark_stuck_jobs` in einen definierten Fehlerzustand.
- **Lock + Heartbeat pro Worker:** Jeder Worker schreibt ein eigenes Lockfile inkl. `heartbeat_at` und quarantänisiert stale/beschädigte Locks; dadurch werden Parallelstarts abgefangen und Crash-Reste bereinigt.
- **Abarbeitungsreihenfolge Scan:** Scan-Batches laufen priorisiert in der Reihenfolge `scan_backfill_tags` → `rescan_media` → `scan_path`, damit Metadaten-/Rescan-Korrekturen zuerst stabilisiert werden.
- **Ollama-Preflight & Health-Gate:** Vor Verarbeitung prüft Ollama Prompt-/Runtime-Voraussetzungen und Health; bei Down-Status werden Jobs als `blocked_by_ollama` markiert statt unkontrolliert fehlzuschlagen.
- **Definierte Übergabe je Worker:**
  - `scan_path_cli.php` enqueued `scan_path`-Jobs, `scan_worker_cli.php` übernimmt und schreibt nach `media`, `scan_results`, `import_log`.
  - Forge-Enqueue aus UI/CLI schreibt `forge_*`-Jobs, `forge_worker_cli.php` verarbeitet und persistiert Request/Response + Audit.
  - Media-Additivjobs (`integrity_check`, `hash_compute`, `upscale`) werden von `media_worker_cli.php` übernommen und in `media_meta`/Derivaten hinterlegt.
  - Rename-Queue (`library_rename`) wird von `library_rename_worker_cli.php` transaktional (Datei + DB + Meta) abgearbeitet.
  - `ollama_enqueue_cli.php` erzeugt Stage-Jobs, `ollama_service_cli.php` startet den Loop und `ollama_worker_cli.php` verarbeitet in Child-Prozessen mit Timeout/Kill-Pfaden.

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

### Integrity/Hash/Upscale Jobs
- Neue Media-Jobs laufen über `media_worker_cli.php`:
  ```bash
  php SCRIPTS/media_worker_cli.php --limit=50
  ```
- Integrity-Check schreibt `media_meta` (z. B. `integrity.ok`, `integrity.error_code`, `integrity.checked_at`).
- SHA256-Hash wird in `media_meta` abgelegt (`hash.sha256`, `hash.checked_at`) und im UI für Dupe-Grouping genutzt.
- Upscale erzeugt neue Media-Datensätze (Child) im Derivatives-Pfad und setzt `variant.preferred_media_id` am Parent.
- UI zeigt automatisch HD-Derivate (Badge „HD“), das Original bleibt per „Original (SD)“-Toggle erreichbar.
- Scanner-Character-Tags werden als `danbooru.character.v1` gespeichert und zusätzlich in `media_meta` (`scanner.character.tags`) dokumentiert.

### Forge Runtime-Validierung
- Forge-Aktionen (`forge_regen`, `forge_repair_start`, Modellliste) validieren die Runtime-Config mit `reason_code`, `missing_keys`, `config_path` und liefern diese Details als sichtbare Fehlermeldung statt generischem „deaktiviert“.
- Bei Forge-Healthcheck-Fehlern werden `reason_code`, `http_code` und `target_url` im Operation-/Audit-Log persistiert, damit Auth/Endpoint-Probleme eindeutig unterscheidbar sind.

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
- Web-Fast-Path: `WWW/ollama.php action=status` läuft standardmäßig ohne DB-Zugriffe (`details=0`) und antwortet aus Runtime-Dateien (`runtime/ollama_global_status.json`, Spawn-Status, Heartbeat/Lock). Bei `details=1` wird DB nur mit sehr kurzem `busy_timeout` geöffnet; bei `SQLITE_BUSY/LOCKED` kommt sofort `status=busy` mit Light-/Stale-Daten.
- `action=run` ist non-blocking/fire-and-forget: Pending-Counts kommen aus Runtime-Status; der Spawn läuft ohne Verify-Wartefenster (`verifyWindowSeconds=0`).
- Das Dashboard (`WWW/dashboard_ollama.php`) rendert initial ohne DB-Queries und lädt nur den Light-Status; Detaildaten bleiben explizit/optional.

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
- Child-Timeouts/Cancels werden nach kurzer Grace-Period hart beendet (posix `SIGKILL` oder `taskkill /F /T`) und als System-Error geloggt.
- Der Worker schreibt zusätzlich einen Runtime-Heartbeat nach `LOGS/runtime/ollama_worker_heartbeat.json` (`ts_utc`, `pid`, `state`, optional `current_job_id`/`last_batch_ts`); dieser wird im Status-Polling primär genutzt.
- Der Worker schreibt einen kompakten Runtime-Cache nach `LOGS/runtime/ollama_global_status.json` (`queue_pending`, `queue_queued`, `queue_running`, `worker_pid`, `worker_running`, optional `last_error`).
- Ollama- und System-JSONL-Logs werden automatisch rotiert, sobald sie 10 MB überschreiten (Archiv via `.old` oder Trunkierung auf 1000 Zeilen).
- `action=status` nutzt standardmäßig den Light-Status aus Runtime-Cache; schwere DB-Aggregate werden nur bei `details=1` oder fehlendem Cache ausgeführt.
- `WWW/app.js` pollt dynamisch (kürzer bei aktivem Worker, länger im Idle) und nutzt Backoff bei Fehlern/Stale-Cache.
- Web setzt für Ollama-Status einen kurzen SQLite `busy_timeout` (200ms), Worker einen höheren (3500ms), um Lock-Contention zu reduzieren.
- Runner/Spawn-Antworten liefern ein einheitliches Statusschema mit `status` (started|running|locked|busy|start_failed|open_failed|config_failed) und `reason_code` (z. B. `spawn_unverified`, `lock_busy`, `log_root_unavailable`), damit UI/CLI zwischen Lock, IO/Path und Spawn-Fehlern eindeutig unterscheiden.
- Scan-Worker-Starts aus Web-Aktionen laufen non-blocking (`verifyWindowSeconds=0`) und liefern `status=started_unverified`, damit der PHP Built-in Server nicht durch Verifikations-Polling blockiert wird.
- Der letzte Spawn-Status des Scan-Workers wird in `LOGS/scan_worker_spawn_last.json` persistiert (`ts_utc`, `requested_by`, `pid`, `command`, `status`, `reason_code`, `out_log`, `err_log`).
- Scan-Worker-Spawn-Logs werden pro Start timestamped geschrieben (`scan_worker_spawn_<timestamp>.out.log/.err.log`), damit Redirect-Logs nicht überschrieben werden; die konkreten Pfade werden in `scan_worker_spawn_last.json` persistiert und in `rescan_single_job` geloggt.
- Scanner-Konfigurationsfehler (`scanner_not_configured`, `scanner_auth_missing`) werden als harte Job-Fehler gespeichert (`jobs.error_message` + `forge_response_json`) und zusätzlich in `LOGS/scanner_ingest.jsonl` als `response_type_detected=config_error` inkl. `missing_keys`/`config_path` protokolliert.
- PixAI-Scanner-Auth: Primär über `scanner.token`; bei `/check` und `/batch` wird exakt `Authorization: <token>` gesendet (ohne automatisches `Bearer`-Präfix). `scanner.api_key` + `scanner.api_key_header` bleibt ein optionaler Alternativmodus.
- Runtime-Validation für Scanner-Auth meldet eindeutig `required_any=["scanner.token","scanner.api_key+scanner.api_key_header"]`. Fehlende Felder werden präzise als `scanner.token` oder `scanner.api_key_header` ausgewiesen.
- Frühe Scanner-Config-Fehler erzeugen zusätzlich `scanner_persist`-Events (`response_type_detected=config_error`) und eine sichtbare Worker-Zeile mit `reason_code`, `missing_keys` und `config_path`, damit „Scanner bekommt nichts“ klar diagnostizierbar bleibt.
- `health.php` meldet `scan_worker_running` (primär Heartbeat-Freshness, Fallback Lockfile), damit die UI den tatsächlichen Worker-Laufzustand sichtbar machen kann.
- Worker-„running“ wird zentral über Lock/Heartbeat geprüft (`is_ollama_worker_running`): Launcher-Locks oder `web:*`-Owner zählen nicht, und Locks mit `php_server.pid` gelten als ungültig.

### Logpfade
- **Log-Root:** `paths.logs` aus der Config, sonst `LOGS/`
- **Ollama-Worker:** `LOGS/ollama_worker.lock`, `LOGS/ollama_worker.out.log`, `LOGS/ollama_worker.err.log`
- **Ollama-Launcher:** `LOGS/ollama_launcher.lock`
- **Ollama-Jobs:** `LOGS/ollama_jobs.jsonl`, `LOGS/ollama_errors.jsonl`
- **Ollama-Service:** `LOGS/ollama_service.jsonl`
- **Ollama-Status:** `LOGS/ollama_status.json`
- **Ollama Runtime:** `LOGS/runtime/ollama_worker_heartbeat.json`, `LOGS/runtime/ollama_global_status.json`
- **Worker-Locks:** `LOGS/scan_worker.lock.json`, `LOGS/forge_worker.lock.json`, `LOGS/library_rename_worker.lock.json`, `LOGS/media_worker.lock.json`
- **Spawn-Logs:** `LOGS/scan_worker_spawn.*`, `LOGS/scan_worker_spawn_last.json`, `LOGS/forge_worker_spawn.*`, `LOGS/ollama_worker_spawn.last.json`
- **System-Fehlerlog:** `LOGS/system_errors.jsonl` (kritische IO/Spawn-Fehler)

## Web UI Seiten
- `mediadb.php`: Listing/Filter der Medien.
- `media_view.php`: Detailansicht (Metadaten, Tags, Prompts, Assets).
- `dashboard_ollama.php`: Ollama-Übersicht (nur Loopback/Intern) inkl. System-Errors (Last 50) aus `LOGS/system_errors.jsonl`.
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
- **Ollama-Enqueue-Performance:** Das Enqueue fasst Job-Erstellung in eine Transaktion; bei Bedarf WAL/`synchronous=NORMAL` und `busy_timeout` in der SQLite-Config prüfen.
- **Jobs hängen/Queue voll:** `jobs_admin.php` und `jobs_prune.php` nutzen.
- **Scanner/Ollama/Forge nicht erreichbar:** `base_url` in der Config prüfen.
- **Scanner empfängt keine Bilddaten auf Port 8000:** Scanner-Route prüfen. Standard ist `scanner.image_endpoint=/check`; bei abweichender API (z. B. `/predict`) die Route in `CONFIG/config.php` setzen.
- **Schema-Drift in `jobs` (z. B. fehlendes `payload_json`):** `php SCRIPTS/migrate.php` ausführen. Dashboard/API melden bei fehlgeschlagener Migration explizit "DB-Migration erforderlich" statt mit SQL-Fehlern abzubrechen.

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
- **Markdown-Wrapper:** JSON-Extraktion toleriert eingebettete `json`-Blöcke und extrahiert den ersten/letzten `{...}`-Block für robustes Parsing.

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

- Web-DB-Fast-Fail: WWW-Endpunkte nutzen `sv_open_pdo_web()` mit minimalem SQLite-`busy_timeout` (max. 25ms). Bei `SQLITE_BUSY/LOCKED` antworten `thumb.php`, `media_stream.php`, `mediadb.php`, `media_view.php` und `ollama.php` sofort (Busy/Placeholder statt Hänger).
- Web-Kontext ist global über `SV_WEB_CONTEXT` markiert. In diesem Kontext sind DB-Retries deaktiviert (`sv_db_exec_retry()` fail-fast) und Prozess-Probes (`tasklist`/`ps`) werden nicht ausgeführt.
- `internal_ollama.php` und `jobs_prune.php` nutzen ebenfalls `sv_open_pdo_web()`, damit interne WWW-Endpunkte nie mit langem SQLite-Timeout blockieren.
- `internal_ollama.php action=run_once` führt keinen synchronen Batch mehr im Request aus, sondern stößt nur noch den Hintergrund-Spawn an und antwortet sofort.
