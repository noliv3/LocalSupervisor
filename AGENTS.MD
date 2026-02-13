# AGENTS – Supervisor (Repo: LocalSupervisor)

Supervisor ist ein lokales System zum Verwalten großer Medienbibliotheken (Bilder/Videos). Es kombiniert eine Medien-DB, Scanner-Import, Job-Queue/Worker und eine Web-UI für Listing/Detailansichten. Das Repository heißt „LocalSupervisor“, der Produktname ist „Supervisor“. Ollama und Forge sind optionale Module innerhalb des Systems.

## Invarianten (verbindlich)
- Schemaänderungen nur über Migrationen; Schema-Checks in `SCRIPTS/db_helpers.php` mitpflegen.
- README/AGENTS dürfen nie auf ein einzelnes Modul reduziert werden (Ollama ist nur ein Teilmodul).
- Keine Secrets/Keys oder Base64-Medien in Logs oder DB schreiben.
- Interne Web-Endpoints nur mit `internal_api_key` und Loopback/Whitelist absichern.
- Jede Feature-Änderung erfordert ein README-Update im selben Change.

## Arbeitsstil
- Kleine Changesets.
- Kurzes Feedback, keine kompletten Dateien im Chat.
- Keine Tests ausführen.

## Modul-Karte

### Scan/Scanner
- Scanner-Import läuft über `scan_path_cli.php` → `scan_worker_cli.php`.
- Ergebnisse landen in `scan_results`, Medien in `media`.
- Pfad-Grenzen kommen aus `CONFIG/config.php` (`paths`).
- Import-Logs schreiben nach `import_log`.

### Jobs/Worker
- Zentrale Queue ist `jobs` (Status, Fortschritt, Fehlercodes).
- Worker pro Modul: Scan, Forge, Ollama.
- Verwaltung über `jobs_admin.php` und `jobs_prune.php`.
- Queue-Limits kommen aus `config.php` (`jobs`).

### Web UI
- `mediadb.php` (Listing/Filter), `media_view.php` (Detail).
- `dashboard_ollama.php` ist loopback-intern.
- `internal_ollama.php` ist interner API-Endpunkt.
- Healthcheck über `health.php`.

### Forge
- Forge-Jobs erzeugen `jobs`-Einträge (Typen `forge_*`).
- Rezepte kommen aus `SCRIPTS/forge_recipes.php` bzw. `CONFIG/forge_recipes.json`.
- Worker läuft über `forge_worker_cli.php`.
- Ergebnisse und Audit in `forge_request_json`/`forge_response_json` + `audit_log`.

### Ollama Pipeline
- Prompt-Templates liegen in `PROMPTS/ollama/*.txt`.
- Verarbeitung läuft über `ollama_enqueue_cli.php` + `ollama_service_cli.php`.
- Ergebnisse in `ollama_results` und `media_meta`.

#### Stages + media_meta-Keys
**Stage 1 – Caption/Title**
- `ollama.caption`, `ollama.caption.meta`
- `ollama.title`, `ollama.title.meta`

**Stage 2 – Prompt Eval**
- `ollama.prompt_eval.score`, `ollama.prompt_eval.meta`

**Stage 3 – Tags Normalize**
- `ollama.tags_raw`, `ollama.tags_normalized`, `ollama.tags_map`, `ollama.tags_normalize.meta`

**Stage 4 – Quality/Domain**
- `ollama.quality.score`, `ollama.quality.flags`
- `ollama.domain.type`, `ollama.domain.confidence`
- `ollama.quality.meta`

**Stage 4b – NSFW Classify**
- `ollama.nsfw.score`, `ollama.nsfw.flags`, `ollama.nsfw.category`
- `ollama.nsfw.meta`

**Stage 5 – Prompt Recon**
- `ollama.prompt_recon.prompt`, `ollama.prompt_recon.negative`
- `ollama.prompt_recon.confidence`, `ollama.prompt_recon.style_tokens`, `ollama.prompt_recon.subject_tokens`
- `ollama.prompt_recon.meta`

**Stage 6 – Embeddings**
- `ollama.embed.text.model`, `ollama.embed.text.dims`, `ollama.embed.text.hash`, `ollama.embed.text.vector_id`
- `ollama.embed.meta`

**Stage 7 – Dupe Hints**
- `ollama.dupe_hints.top`, `ollama.dupe_hints.threshold`, `ollama.dupe_hints.meta`

**Global (alle Stages)**
- `ollama.last_run_at`, `ollama.stage_version`

### DB/Consistency/Audit
- Zentrales Schema: `DB/schema.sql` + Migrationen.
- Konsistenzprüfungen: `consistency_check.php`, Logs in `consistency_log`.
- Audit-Events in `audit_log`.

## Verboten
- Keine großflächigen Umbauten ohne explizite Phase.
- Keine Entfernung bestehender Tools/Runner ohne Ersatz.


## Laufzeit-Hinweise (aktuell)
- PixAI-Scanner erwartet für `/check` und `/batch` primär `Authorization: <scanner.token>` (ohne Bearer-Präfix). `scanner.api_key` + `scanner.api_key_header` ist nur ein optionaler Alternativmodus.
- WWW-Routen laufen im `SV_WEB_CONTEXT`: DB-Zugriffe sind fail-fast (`sv_open_pdo_web`, keine Retry-Sleeps bei `SQLITE_BUSY/LOCKED`) und OS-Prozess-Probes (`tasklist`/`ps`) sind im Web-Kontext untersagt.
