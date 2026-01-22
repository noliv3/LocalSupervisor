# pipeline_ollama.md

## Ziel
Stufenplan für das Ollama-Modul (MVP → Erweiterungen) mit klaren Job-Typen, Inputs/Outputs, DB-Feldern, Run-Conditions und Failure-Modes.

---

## MVP (Stufe 1–3)
### Stufe 1: caption/title/description
**Job-Typen**: `ollama_caption`, `ollama_title`
- **Inputs**: Bild (base64), `media`-Metadaten, ggf. existierender Prompt.
- **Outputs**:
  - `media_meta`: `ollama.caption`, `ollama.title`, `ollama.description`
  - optional: `media_meta.ollama.model`, `media_meta.ollama.last_run_at`
- **DB-Felder**: nur `media_meta` (MVP, ohne Schemaänderung).
- **Run-Conditions**:
  - Nur wenn kein vorhandener Caption/Title vorhanden oder wenn `force=true`.
  - Job dedupe pro `media_id` + `job_type`.
- **Failure-Modes**:
  - Timeout → Retry/Backoff.
  - Invalid JSON → Job error, kein Persist.

### Stufe 2: tag-normalization + weighting
**Job-Typ**: `ollama_tags_normalize`
- **Inputs**: existing Tags, Caption/Prompt-Text, optional Scan-Flags.
- **Outputs**:
  - `media_meta`: `ollama.tags_raw`, `ollama.tags_normalized`
  - optional: Update `tags` + `media_tags` (confidence/weight)
- **DB-Felder**: `tags`, `media_tags`, `media_meta`.
- **Run-Conditions**:
  - Tag-Mangel oder hohe Inkonsistenz (z. B. Tagzahl < Minimum).
- **Failure-Modes**:
  - Duplicate-Tags → dedupe + locked-Policy beachten.

### Stufe 3: quality/defect detection
**Job-Typ**: `ollama_quality`
- **Inputs**: Bild, Caption, Tags, Scan-Flags.
- **Outputs**:
  - `media_meta`: `ollama.quality` (Defects, Hinweise)
  - optional: Update `media.quality_status`, `media.quality_score`
  - optional: `media_lifecycle_events` (quality_eval)
- **Run-Conditions**:
  - `media.quality_status = unknown` oder `force=true`.
- **Failure-Modes**:
  - Unklare Ergebnisse → `quality_status=review`.

---

## Erweiterungen (Stufe 4–7)
### Stufe 4: duplicate assist
**Job-Typ**: `ollama_duplicate_assist`
- **Inputs**: Bild, Hash/pHash (wenn vorhanden), ähnliche Medien (hash matches).
- **Outputs**:
  - `media_meta`: `ollama.duplicate_assist` (Ähnlichkeits-Cluster, Hinweise)
- **DB-Felder**: `media`, `media_meta`.
- **Run-Conditions**:
  - Nur wenn Duplikat-Kandidaten existieren (hash groups oder pHash in Zukunft).
- **Failure-Modes**:
  - Keine Kandidaten → noop.

### Stufe 5: prompt reconstruction + confidence
**Job-Typ**: `ollama_prompt_recon`
- **Inputs**: Bild, Tags, Caption, evtl. vorhandene Prompt-Fragmente.
- **Outputs**:
  - `media_meta`: `ollama.prompt_reconstruction` (prompt + confidence)
  - optional: `prompt_history` (source=ollama_prompt_recon)
- **DB-Felder**: `media_meta`, optional `prompt_history`.
- **Run-Conditions**:
  - Nur wenn Prompt fehlt/unvollständig oder Qualität C.
- **Failure-Modes**:
  - Niedrige Confidence → nur Meta speichern, kein Prompt schreiben.

### Stufe 6: multi-score (fidelity/aesthetic/novelty/compliance/completeness)
**Job-Typ**: `ollama_score_multi`
- **Inputs**: Bild + Caption/Tags/Prompt.
- **Outputs**:
  - `media_meta`: `ollama.scores` (strukturierte Scores)
- **DB-Felder**: `media_meta`.
- **Run-Conditions**:
  - Qualitätsanalyse vorhanden oder gesetzter Score-Batch.
- **Failure-Modes**:
  - Schema-Validation fail → Job error.

### Stufe 7: policy flags (publishable/needs_review/blocked_reason)
**Job-Typ**: `ollama_policy_flags`
- **Inputs**: Quality-Result, Score-Result, NSFW-Flags.
- **Outputs**:
  - `media_meta`: `ollama.policy_flags`
  - optional: Update `media.quality_status` + Event
- **DB-Felder**: `media_meta`, `media`, `media_lifecycle_events`.
- **Run-Conditions**:
  - Policy-Decision nötig (z. B. Publikations-Staging).
- **Failure-Modes**:
  - Konflikte → `needs_review=true`.

---

## Gemeinsame Regeln
- **Idempotenz**: Key-basierte `media_meta` Upserts; Tags dedupe; keine Doppel-Events.
- **Determinismus**: Optionale Seeds/Temperature=0 für reproduzierbare Ergebnisse.
- **Logging**: Keine Base64/Secrets; nur Hash/IDs.
- **Retry/Backoff**: Netzwerkfehler → 2–3 Retries (exponentiell).
