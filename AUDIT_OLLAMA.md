# AUDIT_OLLAMA.md

## Zweck
Dieses Dokument beschreibt den Ist-Zustand der bestehenden Supervisor-Architektur, damit das neue Ollama-Modul ohne unnötige Umbauten geplant werden kann. Fokus: bestehende Pipelines, Datenmodell, Queues/Jobs, Scans/Tagging, Scoring, Reprocess/Rescan-Flows und relevante Dateien.

## Ist-Zustand – Pipelines & Entry Points
### CLI/Worker
- **Scan-CLI**: `SCRIPTS/scan_path.php` führt Pfad-Scans aus, nutzt `scan_core.php` und den konfigurierten Scanner (HTTP) für Tags/NSFW. Ergebnis: Import + Scan-Ergebnis + Tags. (Ein Einstiegspunkt für neue Scan-Workflows.)
- **Scan-Worker**: `SCRIPTS/scan_worker_cli.php` verarbeitet Scan-/Rescan-/Backfill-Jobs in Batches, mit Lockfile-Schutz und Ergebniszählung.
- **Forge-Worker**: `SCRIPTS/forge_worker_cli.php` verarbeitet Forge-Regenerationen/Reparaturen (bestehende Prompt-/Tag-Logik).

### Web-UI
- `WWW/mediadb.php` zeigt Metadaten/Filter (u. a. Prompt-Qualität, Tags, Scan-Status) und triggert Rescan-Jobs.

### Operations-Zentrale
- `SCRIPTS/operations.php` bündelt Job-Queue, Status-Fortschreibung, Queue-Limits, Quality-/Prompt-Quality-Logik sowie Forge-Job-Handling.

## Jobs/Queues
- Jobs liegen in `jobs` (DB); Status-Flow: `queued` → `running` → `done/error` (zusätzlich `canceled`).
- Queue-Limits pro Gesamt/Typ/Media werden über `CONFIG/config.example.php` gesteuert; Enforcement liegt in `operations.php`.
- Bestehende Job-Typen: `scan_path`, `rescan_media`, `scan_backfill_tags`, `library_rename`, `forge_regen` (und Varianten).

## Scanner/Tagger
- Externer Scanner läuft per HTTP (konfigurierbar). Der Scan speichert:
  - `scan_results` (nsfw_score, flags, raw_json)
  - Tags in `tags` + `media_tags`
  - Logevents in `LOGS/scanner_ingest.jsonl`
- `scan_core.php` interpretiert unterschiedliche Scanner-Response-Formate und normalisiert die Ergebnisse.

## Prompt-/Scoring-Mechanik
- **Prompt-Qualität**: A/B/C-Klassifizierung in `operations.php` (Ableitung aus Prompt-Länge/Metadaten).
- **Quality-Status**: `media.quality_status`, `media.quality_score`, `media.quality_notes` + `media_lifecycle_events` für Audit-Trail.
- **Prompt-Historie**: `prompt_history` mit Versionierung + Dedupe-Mechanik.

## Reprocess/Rescan-Flows
- **Rescan einzelnes Media**: `sv_enqueue_rescan_media_job` → `sv_process_rescan_media_job` → `sv_rescan_media` (inkl. Cancel-Checks und Scan-Result-Persistierung).
- **Rescan “unscanned”**: `sv_run_rescan_unscanned` für Medien ohne Scan-Ergebnis.
- **Backfill Tags**: eigener Job-Typ `scan_backfill_tags`.

## Datenmodell-Audit (exists/missing)
### Tabellen (Auszug)
| Tabelle | Zweck | Exists |
| --- | --- | --- |
| `media` | Medien-Stammdaten (Pfad, Typ, Größe, Status) | ✅ |
| `tags`, `media_tags` | Tag-Lexikon und Zuweisungen | ✅ |
| `scan_results` | Scanner-Outputs (NSFW/Flags/Raw) | ✅ |
| `prompts`, `prompt_history` | Prompt-Metadaten + Historie | ✅ |
| `jobs` | Queue/Worker-Status | ✅ |
| `media_lifecycle_events` | Quality-/Lifecycle-Events | ✅ |
| `media_meta` | Key/Value-Metadaten | ✅ |

### Felder – Mapping (exists/missing)
| Feld | Quelle/Kommentar | Exists |
| --- | --- | --- |
| `title`, `description`, `caption`, `alt` | Nicht vorhanden → geeignet für Ollama-Erweiterung | ❌ |
| `nsfw_score` | `scan_results.nsfw_score` | ✅ |
| `hash`, `pHash` | `media.hash` vorhanden, pHash nicht vorhanden | ⚠️ (pHash fehlt) |
| `file_path` | `media.path` | ✅ |
| `prompt_text` / `negative_prompt` | `prompts.prompt`, `prompts.negative_prompt` | ✅ |
| `sampler`, `seed`, `model` | `prompts` | ✅ |
| `created_at` | `media.created_at` | ✅ |
| `error_flags` | `scan_results.flags`, `jobs.error_message` | ✅ |
| `policy_flags` (publishable/needs_review/blocked_reason) | Nicht vorhanden → Kandidat in `media_meta` | ❌ |
| `quality_score` | `media.quality_score` | ✅ |
| `duplicate_assist` | Nicht vorhanden → Kandidat in `media_meta` | ❌ |
| `score_systems` (fidelity/aesthetic/novelty/…) | Nicht vorhanden → Kandidat in `media_meta` | ❌ |

### Vorschlag neuer Felder (nur Dokumentation)
- **`media_meta`-Schlüssel**: `ollama.caption`, `ollama.title`, `ollama.description`, `ollama.tags_raw`, `ollama.tags_normalized`, `ollama.quality`, `ollama.duplicate_assist`, `ollama.prompt_reconstruction`, `ollama.scores`, `ollama.policy_flags`, `ollama.model`, `ollama.version`, `ollama.last_run_at`.
- **Optionaler pHash**: `media.phash` (oder `media_meta` als Zwischenlösung).

## Relevante Dateien/Ordner (Kurzbeschreibung)
- `DB/schema.sql` – Referenzschema mit media, tags, scan_results, prompts, jobs, lifecycle, meta.
- `CONFIG/config.example.php` – Scanner-/Jobs-Konfiguration inkl. Queue-Limits.
- `SCRIPTS/operations.php` – Job-Queue, Quality-Status, Prompt-Qualität, Forge-Jobs.
- `SCRIPTS/scan_core.php` – Scanner-Integration, Tag-Persistierung, Scan-Logs, Rescan.
- `SCRIPTS/scan_worker_cli.php` – Scan/Rescan/Backfill-Worker.
- `SCRIPTS/scan_path.php` – CLI-Scan für Pfade/Imports.
- `WWW/mediadb.php` – Filter/Rescan-Aktionen in der Web-UI.

## Risiken & Constraints für Ollama-Planung
- **Keine Schema-Migrationen im MVP**: neue Ollama-Daten zunächst in `media_meta` speichern.
- **Queue-Limits** beachten: neue Ollama-Jobtypen müssen `sv_enforce_job_queue_capacity` respektieren.
- **Logging**: Keine Base64-Images oder Secrets in Logs (nur Hashes/Truncation).
- **Idempotenz**: Wiederholte Jobs dürfen keine Duplikate erzeugen (z. B. `media_meta` upsert + tags dedup).
