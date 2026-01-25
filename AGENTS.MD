# AGENTS – Developer-Regeln (Phase 3)

## Verbindliche Regeln
- **Keine Tests ausführen.**
- **Keine UI außerhalb Dashboard/Media-Resultpanel ändern.**
- **Keine Doku-Änderungen außer Phase 3.**
- **Ollama:** keine Base64/Secrets, keine Vektoren in `meta` oder Logs.

## Phasenpipeline (Referenz: Stages + media_meta-Keys)
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
