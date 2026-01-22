# Schnittstellen-Analyse – Ollama-Modul

> Ziel: Definieren, **wo** Ollama logisch andockt (ohne Implementierung) und wie Ein-/Ausgabedaten auf den bestehenden Datenbestand abgebildet werden.

## 1) Andockpunkte im aktuellen System

### Job-Queue & Batch-Worker
- **Jobs-Tabelle + Queue-Limits** als standardisierter Einstiegspunkt für Batch-Aktionen.【DB/schema.sql】【SCRIPTS/operations.php】
- **Ollama-Stage-1** nutzt bereits `jobs` + `media_meta` und kann als Muster für weitere Stufen dienen.【SCRIPTS/ollama_jobs.php】
- **CLI-Worker** als geplante Batch-Laufzeit (CRON/Task/Manuell).【SCRIPTS/ollama_worker_cli.php】

### Medien-Metadaten (Key/Value)
- `media_meta` dient als flexibler Speicher für zusätzliche Analysewerte (z. B. Caption/Title).【DB/schema.sql】【SCRIPTS/ollama_jobs.php】

### Scanner-/NSFW-Quellen
- `scan_results` + Tagging liefern NSFW-Score und Tags als Kontextdaten für Ollama-Prompts.【DB/schema.sql】【SCRIPTS/scan_core.php】

### Prompt-/Model-Metadaten
- `prompts`/`prompt_history` liefern Prompt-/Model-Infos für Prompt-Match oder Quality-Kontext.【DB/schema.sql】【SCRIPTS/prompt_parser.php】

### UI/Operator-Flows
- UI-Komponenten zeigen Prompt-Qualität, Issues und Rescan-Workflows, die später mit Ollama-Ergebnissen angereichert werden können (ohne UI-Änderung in diesem Schritt).【WWW/media_view.php】【WWW/mediadb.php】

## 2) Input-Quellen für Ollama (konzeptionell)

| Input-Feld | Herkunft (Quelle) | Bemerkung |
| --- | --- | --- |
| `media_id` | `media.id` | Primärer Identifier für Batch-Läufe.【DB/schema.sql】 |
| `image_path` | `media.path` | Dateipfad für lokale Vision-Analyse.【DB/schema.sql】 |
| `image_base64` | Dateiinhalt (fs) | Nur zur Übermittlung an Ollama; **nicht** persistieren/loggen.【SCRIPTS/ollama_jobs.php】 |
| `tags[]` | `media_tags` + `tags` | Kontext für Prompt-Match oder Deskriptoren.【DB/schema.sql】【SCRIPTS/scan_core.php】 |
| `nsfw_score` | `scan_results.nsfw_score` | Scanner-Score für Safety/Rating-Kontext.【DB/schema.sql】 |
| `prompt` / `negative_prompt` | `prompts` (latest) | Prompt-Text und Negativprompt für Match-Analyse.【DB/schema.sql】 |
| `scanner_meta` | `scan_results.flags`/`raw_json` | Ergänzende Scanner-Metadaten (read-only).【DB/schema.sql】【SCRIPTS/scan_core.php】 |
| `error_flags` | `jobs.error_message` + `scan_results` | Bestehende Fehlerkontexte als Input-Signale.【DB/schema.sql】 |

## 3) Output-Daten (Ollama → Supervisor)

### Zieldaten (konzeptionell)
- **Titel** (Kurzform) – optional als `media_meta` oder neues Feld.
- **Beschreibung/Caption** – optional als `media_meta`.
- **Qualitätswertung** – Abbildung auf `media.quality_score`/`quality_status` oder neues spezielles Feld.
- **Prompt-Match-Score** – neues Feld in `media_meta` oder eigene Tabelle.
- **Widersprüche/Fehlende Elemente** – strukturierte Liste in `media_meta`.
- **Flags**: `broken`, `duplicate_suspect`, `needs_rescan` – neue Flags in `media_meta` oder neue Tabelle.

### Mapping auf bestehende Felder

| Output | Mögliche Zuordnung | Status |
| --- | --- | --- |
| `title` | `media_meta` (`ollama.title`) | **vorhanden (Key/Value)**.【DB/schema.sql】 |
| `description/caption` | `media_meta` (`ollama.caption`/`ollama.description`) | **teilweise vorhanden** (Stage-1 nutzt `ollama.caption`).【SCRIPTS/ollama_jobs.php】 |
| `quality_score` | `media.quality_score` | **vorhanden**, aber ohne Modell-/Version-Referenz.【DB/schema.sql】 |
| `quality_status` | `media.quality_status` | **vorhanden**, Status-Setzung aus Ollama zu definieren.【DB/schema.sql】 |
| `prompt_match_score` | `media_meta` (`ollama.prompt_match_score`) | **neu (konzeptionell)** – kein Feld vorhanden.【DB/schema.sql】 |
| `contradictions[]` | `media_meta` (`ollama.contradictions`) | **neu (konzeptionell)** – kein Feld vorhanden.【DB/schema.sql】 |
| `missing_elements[]` | `media_meta` (`ollama.missing_elements`) | **neu (konzeptionell)** – kein Feld vorhanden.【DB/schema.sql】 |
| `duplicate_suspect` | `media_meta` (`ollama.duplicate_suspect`) | **neu (konzeptionell)** – kein Feld vorhanden.【DB/schema.sql】 |
| `broken` | `media_meta` (`ollama.broken`) | **neu (konzeptionell)** – kein Feld vorhanden.【DB/schema.sql】 |
| `needs_rescan` | `media_meta` (`ollama.needs_rescan`) | **neu (konzeptionell)** – kein Feld vorhanden.【DB/schema.sql】 |

## 4) Konkrete Andock-Optionen (ohne Implementierung)

1. **Job-Queue als Orchestrator**
   - Neue Jobtypen analog zu `ollama_caption`/`ollama_title` definieren (z. B. `ollama_quality`, `ollama_prompt_match`).【SCRIPTS/ollama_jobs.php】
2. **Media-Metadaten als Ergebnisablage**
   - `media_meta` für versionierte Ergebnisse + Flags nutzen; ergänzend Lifecycle-/Quality-Felder in `media` bei Bedarf setzen.【DB/schema.sql】
3. **UI-Aufsatzpunkte**
   - `WWW/media_view.php` und `WWW/mediadb.php` bieten UI-Flächen für zusätzliche Flags/Badges (zukünftige Erweiterung).【WWW/media_view.php】【WWW/mediadb.php】

---

**Hinweis:** Diese Schnittstellen-Analyse legt nur Datenquellen und mögliche Ziel-Felder fest. Implementierung bleibt ausdrücklich ausgeschlossen.
