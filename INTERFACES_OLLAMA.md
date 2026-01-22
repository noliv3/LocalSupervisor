# INTERFACES_OLLAMA.md

## Ziel
Diese Datei listet alle benötigten Schnittstellen für das Ollama-Modul: Input-Quellen, Output-Ziele, Job-Orchestrierung, Konfiguration, Logging und Fehlerkanäle.

## 1) Input-Quellen
### Medien & Dateien
- **Media-Datei** (Binary oder Base64) aus `media.path`.
- **Metadaten** aus `media` (type, width/height, rating, has_nsfw, hash, status).

### Prompt-/Tag-/Scan-Daten
- **Prompts** aus `prompts` (prompt, negative_prompt, model, sampler, seed, size).
- **Tags** aus `tags` + `media_tags` (inkl. locked-Flag, confidence).
- **Scan-Ergebnisse** aus `scan_results` (nsfw_score, flags, raw_json).
- **Media-Meta** aus `media_meta` (z. B. bereits extrahierte Exif-Daten, user votes, curation).

## 2) Output-Ziele
### Persistenz
- **`media_meta`**: primärer Speicher für Ollama-Ergebnisse (JSON als Text), z. B.
  - `ollama.caption`, `ollama.title`, `ollama.description`
  - `ollama.tags_raw`, `ollama.tags_normalized`
  - `ollama.quality`, `ollama.duplicate_assist`
  - `ollama.prompt_reconstruction`
  - `ollama.scores`, `ollama.policy_flags`
  - `ollama.model`, `ollama.version`, `ollama.last_run_at`
- **`tags` + `media_tags`**: optionales Schreiben normalisierter Tags inkl. weights/confidence.
- **`media.quality_status` / `media.quality_score`**: optionales Update nach Policy/Quality-Analyse.
- **`media_lifecycle_events`**: Audit-Event, wenn Quality/Policy geändert wurde.

### UI/Downstream
- **Web-UI**: Anzeige von Caption/Title/Policy-Flags/Quality/Score (über `media_meta`).
- **Export/Feeds**: JSON-Exports aus `media_meta` für Drittsysteme.

## 3) Job-Orchestrierung
### Job-API (intern)
- **enqueue_ollama(job_type, media_id, payload)**
  - Validiert Queue-Limits.
  - Dedupe: wenn ein gleichartiger Job für `media_id` bereits queued/running ist, dedup.
- **process_ollama_job(job_id)**
  - Lädt Media/Meta/Prompt/Tags.
  - Führt Ollama-Call aus.
  - Persistiert Ergebnis (inkl. Event/Audit).
- **persist_ollama_result(media_id, result)**
  - Schreibt in `media_meta`, Tags, ggf. `quality_status` und `media_lifecycle_events`.

### Job-Typen (pro Pipeline-Stufe)
- `ollama_caption`
- `ollama_title`
- `ollama_tags_normalize`
- `ollama_quality`
- `ollama_duplicate_assist`
- `ollama_prompt_recon`
- `ollama_score_multi`
- `ollama_policy_flags`

## 4) Konfiguration
### Pflicht
- `ollama.base_url` (z. B. `http://127.0.0.1:11434`)
- `ollama.model.default`
- `ollama.timeout_ms`
- `ollama.max_image_bytes`

### Optional
- `ollama.vision_model` (Fallback für Bild-Analyse)
- `ollama.text_model` (für reine Textjobs)
- `ollama.retry.max_attempts`
- `ollama.retry.backoff_ms`
- `ollama.deterministic` (temperature/top_p/seed)

## 5) Logs & Monitoring
- **Job-Logs**: `LOGS/ollama_jobs.jsonl` (strukturierte JSONL-Events)
- **Fehler-Logs**: `LOGS/ollama_errors.jsonl` (nur Metadaten, keine Base64)
- **Audit-Log**: `audit_log` für Status-/Policy-Änderungen

## 6) Fehlerkanäle
- Netzwerk-/Timeout-Fehler (HTTP/Connect)
- Modell/Prompt-Fehler (invalid_response_format)
- Validierungsfehler (Schema/JSON-Payload ungültig)
- Persistenzfehler (DB/Constraint/Lock)
- Queue-Limits (rate/overflow)

## 7) Interne Ollama-Client-API
### Signaturen (Pseudo)
```ts
generateText(prompt: string, options: OllamaOptions): Promise<OllamaTextResponse>
analyzeImage(imageBase64: string, prompt: string, options: OllamaOptions): Promise<OllamaVisionResponse>
health(): Promise<OllamaHealthResponse>
listModels(): Promise<OllamaModelsResponse>
```

### Erwartete Optionen
```json
{
  "model": "string",
  "format": "json",
  "temperature": 0.2,
  "top_p": 0.9,
  "seed": 12345,
  "timeout_ms": 20000
}
```

## 8) Sicherheitsregeln (Schnittstellen-übergreifend)
- Keine Secrets/Base64 in Logs.
- Response muss reines JSON sein (keine erklärenden Texte).
- Idempotent: gleiche Inputs überschreiben vorhandene `media_meta`-Keys deterministisch.
