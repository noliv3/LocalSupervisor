# DATA_CONTRACTS_OLLAMA.md

## Ziel
JSON-Schemas für Ollama-Requests/Responses, interne DTOs, Persist-Events und Statusfelder. Alle Responses müssen **reines JSON** sein.

---

## 1) Ollama Client – Requests
### 1.1 generateText
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.generateText.request",
  "type": "object",
  "required": ["prompt", "options"],
  "properties": {
    "prompt": { "type": "string", "minLength": 1 },
    "options": {
      "type": "object",
      "required": ["model", "format"],
      "properties": {
        "model": { "type": "string", "minLength": 1 },
        "format": { "const": "json" },
        "temperature": { "type": "number", "minimum": 0, "maximum": 2 },
        "top_p": { "type": "number", "minimum": 0, "maximum": 1 },
        "seed": { "type": ["integer", "string", "null"] },
        "timeout_ms": { "type": "integer", "minimum": 1000 }
      },
      "additionalProperties": true
    }
  },
  "additionalProperties": false
}
```

### 1.2 analyzeImage
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.analyzeImage.request",
  "type": "object",
  "required": ["image_base64", "prompt", "options"],
  "properties": {
    "image_base64": { "type": "string", "minLength": 1 },
    "prompt": { "type": "string", "minLength": 1 },
    "options": {
      "type": "object",
      "required": ["model", "format"],
      "properties": {
        "model": { "type": "string", "minLength": 1 },
        "format": { "const": "json" },
        "temperature": { "type": "number", "minimum": 0, "maximum": 2 },
        "top_p": { "type": "number", "minimum": 0, "maximum": 1 },
        "seed": { "type": ["integer", "string", "null"] },
        "timeout_ms": { "type": "integer", "minimum": 1000 }
      },
      "additionalProperties": true
    }
  },
  "additionalProperties": false
}
```

---

## 2) Ollama Client – Responses
### 2.1 Standard-Response (Text/Vision)
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.response",
  "type": "object",
  "required": ["ok", "model", "response_json"],
  "properties": {
    "ok": { "type": "boolean" },
    "model": { "type": "string" },
    "response_json": { "type": "object" },
    "usage": {
      "type": "object",
      "properties": {
        "input_tokens": { "type": "integer" },
        "output_tokens": { "type": "integer" }
      },
      "additionalProperties": true
    },
    "error": {
      "type": ["string", "null"]
    }
  },
  "additionalProperties": false
}
```

### 2.2 health
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.health.response",
  "type": "object",
  "required": ["ok", "latency_ms"],
  "properties": {
    "ok": { "type": "boolean" },
    "latency_ms": { "type": "integer", "minimum": 0 },
    "message": { "type": ["string", "null"] }
  },
  "additionalProperties": false
}
```

### 2.3 listModels
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.models.response",
  "type": "object",
  "required": ["ok", "models"],
  "properties": {
    "ok": { "type": "boolean" },
    "models": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["name"],
        "properties": {
          "name": { "type": "string" },
          "tags": { "type": "array", "items": { "type": "string" } }
        },
        "additionalProperties": true
      }
    }
  },
  "additionalProperties": false
}
```

---

## 3) Interne DTOs
### 3.1 OllamaJobPayload
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.job.payload",
  "type": "object",
  "required": ["job_type", "media_id", "input"],
  "properties": {
    "job_type": {
      "type": "string",
      "enum": [
        "ollama_caption",
        "ollama_title",
        "ollama_tags_normalize",
        "ollama_quality",
        "ollama_duplicate_assist",
        "ollama_prompt_recon",
        "ollama_score_multi",
        "ollama_policy_flags"
      ]
    },
    "media_id": { "type": "integer", "minimum": 1 },
    "input": {
      "type": "object",
      "properties": {
        "image_base64": { "type": ["string", "null"] },
        "media_path": { "type": ["string", "null"] },
        "prompt_text": { "type": ["string", "null"] },
        "negative_prompt": { "type": ["string", "null"] },
        "tags": { "type": "array", "items": { "type": "string" } },
        "metadata": { "type": "object" }
      },
      "additionalProperties": true
    },
    "options": {
      "type": "object",
      "properties": {
        "model": { "type": "string" },
        "timeout_ms": { "type": "integer" },
        "deterministic": { "type": "boolean" }
      },
      "additionalProperties": true
    }
  },
  "additionalProperties": false
}
```

### 3.2 OllamaJobResult
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.job.result",
  "type": "object",
  "required": ["job_id", "media_id", "job_type", "status"],
  "properties": {
    "job_id": { "type": "integer" },
    "media_id": { "type": "integer" },
    "job_type": { "type": "string" },
    "status": { "type": "string", "enum": ["queued", "running", "done", "error", "canceled"] },
    "model": { "type": ["string", "null"] },
    "response_json": { "type": ["object", "null"] },
    "error": { "type": ["string", "null"] },
    "latency_ms": { "type": ["integer", "null"] }
  },
  "additionalProperties": false
}
```

---

## 4) Persist-Events
### 4.1 media_meta upsert
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.persist.media_meta",
  "type": "object",
  "required": ["media_id", "meta_key", "meta_value", "source"],
  "properties": {
    "media_id": { "type": "integer" },
    "meta_key": { "type": "string" },
    "meta_value": { "type": "string" },
    "source": { "type": "string", "const": "ollama" }
  },
  "additionalProperties": false
}
```

### 4.2 tag upsert
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.persist.tag",
  "type": "object",
  "required": ["media_id", "tag", "type", "confidence"],
  "properties": {
    "media_id": { "type": "integer" },
    "tag": { "type": "string" },
    "type": { "type": "string" },
    "confidence": { "type": "number", "minimum": 0, "maximum": 1 }
  },
  "additionalProperties": false
}
```

---

## 5) Statusfelder (Normierung)
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "ollama.status.fields",
  "type": "object",
  "properties": {
    "quality_status": { "type": "string", "enum": ["unknown", "ok", "review", "blocked"] },
    "policy_flags": {
      "type": "object",
      "properties": {
        "publishable": { "type": "boolean" },
        "needs_review": { "type": "boolean" },
        "blocked_reason": { "type": ["string", "null"] }
      },
      "additionalProperties": false
    }
  },
  "additionalProperties": false
}
```
