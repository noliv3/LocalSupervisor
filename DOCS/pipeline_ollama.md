# Pipeline-Konzept – Ollama Batch-Analyse

> Ziel: Batch-Pipeline für die komplette Bilddatenbank (konzeptionell, ohne Implementierung).

## 1) Reihenfolge der Pipeline-Stufen

1. **Broken/Valid-Check**
   - Prüfen, ob `media.path` existiert und lesbar ist (Basis aus `media`).【DB/schema.sql】
   - Fehlerhafte Medien werden mit `broken=true` markiert (konzeptionell, z. B. `media_meta`).【DB/schema.sql】

2. **Caption & Titel**
   - Vision-Analyse mit JSON-only Output, Speicherung in `media_meta` (z. B. `ollama.caption`, `ollama.title`).【DB/schema.sql】
   - Stage-1 Muster (Caption/Title) existiert bereits als Referenz für Job-Handling.【SCRIPTS/ollama_jobs.php】

3. **Qualitäts-Score**
   - Ableitung eines Scores (0..100) und Abbildung auf `media.quality_score`/`quality_status` (konzeptionell).【DB/schema.sql】

4. **Prompt-Match / ABC-Rating**
   - Vergleich Bildinhalt vs. Prompt/Tags (Prompt-Quellen aus `prompts`, Tags aus `media_tags`).【DB/schema.sql】
   - Prompt-Quality existiert bereits heuristisch (A/B/C) als Referenzlogik.【SCRIPTS/operations.php】

5. **Duplikat-Hinweise**
   - Indikatoren aus Hash-/Ähnlichkeits-Checks; bestehend: `media.hash`-Index (Duplikate).【DB/schema.sql】
   - Output als `duplicate_suspect` Flag in `media_meta` (konzeptionell).【DB/schema.sql】

## 2) Idempotenz-Regeln

- **Versionierung pro Modell & Prompt**: Jeder Lauf speichert `model.name`, `model.digest`, `prompt_template_version`.
- **Skip-Regel**: Wenn `media_meta` bereits eine identische Version enthält, wird der Schritt übersprungen.
- **Recompute-Regel**: Neu rechnen, wenn
  - `force=true` gesetzt ist,
  - Ergebnisfelder fehlen/leer sind,
  - Modell-/Prompt-Version abweicht.
- **Write-Once-Policy**: Standardmäßig keine Überschreibung bestehender Ergebnisse (außer Force).

## 3) Error-Klassen (Standardisiert)

| Code | Bedeutung | Typische Ursache |
| --- | --- | --- |
| `invalid_image` | Datei fehlt/korrupt | `media.path` nicht lesbar, Format ungültig.【DB/schema.sql】 |
| `timeout` | Anfrage timeout | Ollama reagiert nicht innerhalb Timeout.【SCRIPTS/ollama_client.php】 |
| `model_missing` | Modell nicht vorhanden | Ollama-Instanz ohne Modell/Digest.【SCRIPTS/ollama_client.php】 |
| `oversize` | Bild zu groß | `max_image_bytes` überschritten.【SCRIPTS/ollama_client.php】 |
| `partial_result` | Unvollständige Antwort | Pflichtfelder fehlen/ungültig (JSON-only Vertrag).【DOCS/DATA_CONTRACTS_OLLAMA.md】 |

## 4) Batch-Betrieb (Konzept)

- **Scheduler**: Job-Queue (CLI) als Batch-Runner (ähnlich `ollama_worker_cli.php`).【SCRIPTS/ollama_worker_cli.php】
- **Job-Kapazität**: Queue-Guards (`jobs.queue_max_*`) verhindern Overflows.【CONFIG/config.example.php】
- **Logging**: JSONL-Logs für Erfolg/Fehler (ohne Base64/Secrets).【SCRIPTS/ollama_jobs.php】

---

**Hinweis:** Diese Pipeline-Beschreibung ist rein konzeptionell und dient als Implementierungsplan.
