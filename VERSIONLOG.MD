# VERSIONLOG

## 2026-02-06
- Optimierung der SQLite-Performance durch Transaktions-Batching im Ollama-Enqueue und Anpassung der Pragmas.

## Stage 1 – Caption & Title
- Jobtypen für Caption/Title eingeführt; Ergebnisse werden in `ollama_results` persistiert und in `media_meta` als `ollama.caption`/`ollama.title` (inkl. `.meta`) gespiegelt.

## Stage 2 – Prompt Eval
- Prompt-Scoring via `ollama.prompt_eval.score` + `.meta`.

## Stage 3 – Tags Normalize
- Normalisierte Tags und Mapping in `ollama.tags_raw`, `ollama.tags_normalized`, `ollama.tags_map` + `.meta`.

## Stage 4 – Quality & Domain
- Qualitätsbewertung (`ollama.quality.score`, `ollama.quality.flags`) plus Domain-Typ/Confidence (`ollama.domain.type`, `ollama.domain.confidence`) und `.meta`.

## Stage 5 – Prompt Recon
- Rekonstruktion von Prompt/Negative/Token-Listen (`ollama.prompt_recon.*`) inkl. `.meta`.

## Stage 6 – Embeddings
- Text-Embeddings in `ollama.embed.text.*` (`model`, `dims`, `hash`, `vector_id`) plus `ollama.embed.meta`.

## Stage 7 – Dupe Hints
- Duplikat-Hinweise aus Embeddings in `ollama.dupe_hints.*` (Top/Threshold/Meta).

## Schema-Änderungen (kompakt)
- `ollama_results` und `ollama_vectors` Tabellen inkl. Indizes für Medien/Modus/Embedding.
- Jobs-Tracking erweitert um Cancel/Progress-Felder: `cancel_requested`, `cancelled_at`, `progress_bits`, `progress_bits_total`, `heartbeat_at`, `last_error_code`.
