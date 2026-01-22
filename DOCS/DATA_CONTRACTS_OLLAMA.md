# Datenverträge – Ollama Vision & Supervisor Response

> Ziel: Verbindliche JSON-Contracts für Ollama-Requests und Supervisor-normalisierte Responses.
> **Keine Chain-of-Thought Speicherung**; Antworten müssen JSON-only sein.

## 1) Vision-Request (Supervisor → Ollama)

### Minimalvertrag (JSON)
```json
{
  "request_id": "uuid-oder-ulid",
  "media_id": 12345,
  "model": {
    "name": "llava:latest",
    "digest": "sha256:..."
  },
  "prompt": "<prompt-template> (JSON-only output)",
  "prompt_template_version": "vision_caption_v1",
  "input": {
    "image_base64": "<base64>",
    "image_path": "optional/local/path",
    "image_mime": "image/jpeg"
  },
  "context": {
    "tags": ["tag1", "tag2"],
    "nsfw_score": 0.12,
    "prompt": "optional prompt text",
    "negative_prompt": "optional negative prompt",
    "scanner_meta": {
      "scanner": "pixai_sensible",
      "flags": {"gore": false}
    },
    "error_flags": ["scan_stale"]
  },
  "options": {
    "timeout_ms": 20000,
    "deterministic": true,
    "temperature": 0.0,
    "top_p": 1.0,
    "seed": 42
  }
}
```

### Pflichtfelder
- `request_id`, `media_id`.
- `model.name`, `model.digest`.
- `prompt`, `prompt_template_version`.
- `input.image_base64` **oder** `input.image_path` (mindestens eins).

### Einschränkungen
- **JSON-only** Output erwartet (keine Zusatztexte).
- Base64-Daten **nicht** loggen oder persistieren.

## 2) Normalisierte Supervisor-Response (Ollama → Supervisor)

### Minimalvertrag (JSON)
```json
{
  "request_id": "uuid-oder-ulid",
  "media_id": 12345,
  "model": {
    "name": "llava:latest",
    "digest": "sha256:..."
  },
  "prompt_template_version": "vision_caption_v1",
  "result": {
    "title": "optional short title",
    "description": "optional caption",
    "quality_score": 0.0,
    "prompt_match_score": 0.0,
    "contradictions": ["text mismatch"],
    "missing_elements": ["hands", "background"],
    "flags": {
      "broken": false,
      "duplicate_suspect": false,
      "needs_rescan": false
    }
  },
  "metrics": {
    "latency_ms": 1234,
    "attempts": 1
  },
  "errors": []
}
```

### Pflichtfelder
- `request_id`, `media_id`.
- `model.name`, `model.digest`.
- `prompt_template_version`.
- `result.flags` (mindestens Standard-Flags mit bool-Werten).

### Fehlerfall (JSON-only)
```json
{
  "request_id": "uuid-oder-ulid",
  "media_id": 12345,
  "model": {
    "name": "llava:latest",
    "digest": "sha256:..."
  },
  "prompt_template_version": "vision_caption_v1",
  "result": null,
  "metrics": {
    "latency_ms": 20480,
    "attempts": 3
  },
  "errors": [
    {
      "code": "timeout",
      "message": "Ollama request timed out"
    }
  ]
}
```

## 3) Feld-Definitionen (Kurzform)

| Feld | Typ | Pflicht | Beschreibung |
| --- | --- | --- | --- |
| `model.name` | string | ja | Modellname, wie konfiguriert/aufgerufen. |
| `model.digest` | string | ja | Modell-Digest (z. B. `sha256:...`). |
| `prompt_template_version` | string | ja | Version des Prompt-Templates (z. B. `vision_caption_v1`). |
| `result.title` | string|null | nein | Kurz-Titel (max. 80 Zeichen, wenn genutzt). |
| `result.description` | string|null | nein | Bildbeschreibung/Caption. |
| `result.quality_score` | number|null | nein | Qualitätswert 0..100 (konzeptionell). |
| `result.prompt_match_score` | number|null | nein | Prompt-Bild-Übereinstimmung 0..1 (konzeptionell). |
| `result.contradictions` | array | nein | Liste von Konflikten zwischen Prompt und Bild. |
| `result.missing_elements` | array | nein | Fehlende Bild-Elemente basierend auf Prompt/Tags. |
| `result.flags.*` | boolean | ja | Standard-Flags (broken/duplicate_suspect/needs_rescan). |

---

**Hinweis:** Dieser Vertrag ist **modulunabhängig** und kann für Stage-2+ erweitert werden, ohne Chain-of-Thought zu speichern.
