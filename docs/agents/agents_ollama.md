# agents_ollama.md

## Zweck
Dieses Dokument beschreibt das geplante Ollama-Modul als Supervisor-Agent: Aufgabenbereich, Schnittstellen, Prompt-Templates, Output-Formate, Sicherheits- und Testregeln. Es dient als Implementierungsleitfaden ohne bestehende Logik zu verändern.

## Capabilities
- Bildanalyse (Caption/Title/Description).
- Tag-Normalisierung inkl. Gewichtung.
- Qualitäts-/Defektprüfung.
- Dubletten-Assistent (textuell, nicht visuell deduplizierend).
- Prompt-Rekonstruktion inkl. Confidence.
- Multi-Score (fidelity/aesthetic/novelty/compliance/completeness).
- Policy-Flags (publishable/needs_review/blocked_reason).

## Grenzen
- Keine automatische DB-Migration im MVP.
- Keine Änderungen an bestehenden Scanner-Outputs.
- Keine Base64-Daten in Logs.
- Keine Secrets in Logs/Responses.
- Keine Abhängigkeit von externem Netzwerk (Ollama lokal via HTTP).

## Konfiguration
```json
{
  "ollama": {
    "base_url": "http://127.0.0.1:11434",
    "model": {
      "default": "llava:latest",
      "vision": "llava:latest",
      "text": "llama3:latest"
    },
    "timeout_ms": 20000,
    "max_image_bytes": 4194304,
    "retry": {
      "max_attempts": 3,
      "backoff_ms": 500
    },
    "deterministic": {
      "enabled": true,
      "temperature": 0.0,
      "top_p": 1.0,
      "seed": 42
    }
  }
}
```

## Prompt-Templates (stabil, JSON-only)
**Regel:** `format=json` wird genutzt, Response enthält ausschließlich das JSON-Objekt ohne Zusatztext.

### Versioned Prompt Library (stabile IDs)
| Prompt-ID | Zweck | Input | Output-Keys |
| --- | --- | --- | --- |
| `OLLAMA_CAPTION_V1` | Caption (1 Satz) | Bild | `caption`, `confidence` |
| `OLLAMA_TITLE_V1` | Kurztitel | Bild | `title`, `confidence` |
| `OLLAMA_TAGS_NORMALIZE_V1` | Tag-Normalisierung | Tags + Caption/Prompt | `tags` (list), `notes` |
| `OLLAMA_QUALITY_V1` | Quality/Defect | Bild + Meta | `quality_status`, `issues`, `confidence` |
| `OLLAMA_DUPLICATE_ASSIST_V1` | Duplikat-Hinweise | Bild + ähnliche Medien | `duplicate_hints`, `confidence` |
| `OLLAMA_PROMPT_RECON_V1` | Prompt-Rekonstruktion | Bild + Tags | `prompt`, `negative_prompt`, `confidence` |
| `OLLAMA_SCORE_MULTI_V1` | Multi-Score | Bild + Caption | `scores` |
| `OLLAMA_POLICY_FLAGS_V1` | Policy-Flags | Quality + Scores + NSFW | `publishable`, `needs_review`, `blocked_reason` |

### Template: OLLAMA_CAPTION_V1
```text
Du bist ein Bildbeschreiber. Antworte ausschließlich als JSON. Keine Zusatztexte.
Output-Format:
{"caption":"...","confidence":0.0}
```

### Template: OLLAMA_TITLE_V1
```text
Erzeuge einen kurzen, präzisen Titel (max 80 Zeichen). Antworte ausschließlich als JSON.
Output-Format:
{"title":"...","confidence":0.0}
```

### Template: OLLAMA_TAGS_NORMALIZE_V1
```text
Normalisiere die Tags (lowercase, ascii, kebab-case, keine Duplikate). Antworte ausschließlich als JSON.
Output-Format:
{"tags":[{"tag":"...","type":"content","weight":0.0}],"notes":"..."}
```

### Template: OLLAMA_QUALITY_V1
```text
Bewerte Qualität/Defekte. Antworte ausschließlich als JSON.
Output-Format:
{"quality_status":"ok|review|blocked","issues":["..."],"confidence":0.0}
```

### Template: OLLAMA_DUPLICATE_ASSIST_V1
```text
Analysiere mögliche Duplikate anhand ähnlicher Medien-Infos. Antworte ausschließlich als JSON.
Output-Format:
{"duplicate_hints":[{"media_id":123,"reason":"...","score":0.0}],"confidence":0.0}
```

### Template: OLLAMA_PROMPT_RECON_V1
```text
Rekonstruiere den Prompt aus Bild/Tags. Antworte ausschließlich als JSON.
Output-Format:
{"prompt":"...","negative_prompt":"...","confidence":0.0}
```

### Template: OLLAMA_SCORE_MULTI_V1
```text
Gib Scores 0-1. Antworte ausschließlich als JSON.
Output-Format:
{"scores":{"fidelity":0.0,"aesthetic":0.0,"novelty":0.0,"compliance":0.0,"completeness":0.0}}
```

### Template: OLLAMA_POLICY_FLAGS_V1
```text
Setze Policy-Flags. Antworte ausschließlich als JSON.
Output-Format:
{"publishable":true,"needs_review":false,"blocked_reason":null}
```

## Output-Formate
- Alle Ergebnisse werden als JSON in `media_meta` gespeichert (stringified JSON).
- `tags`-Updates sind optional und müssen `locked` respektieren.

## Sicherheitsregeln
- Keine Base64-Images oder Secrets in Logs.
- Prompts/Responses werden gekürzt (Truncate) bevor sie geloggt werden.
- Idempotente Writes: `media_meta`-Keys werden überschrieben, nicht dupliziert.

## Retry/Backoff
- Max. 3 Versuche bei Timeout/Network.
- Exponentieller Backoff (z. B. 500ms, 1000ms, 2000ms).
- Keine Retries bei invalid JSON/Schema-Fehlern.

## Idempotenz
- Dedupe pro `media_id` + `job_type`.
- `media_meta`-Upsert (ein Key pro Ergebnis).
- Tag-Dedupe: keine doppelten Tag-Zuweisungen.

## Determinismus-Optionen
- `temperature=0`, `top_p=1`, optional `seed`.
- Bei `deterministic.enabled=true` keine randomization.

## Test-Strategie
- **Unit**: Mock Ollama Responses, JSON Schema Validation.
- **Integration**: 1 Bild, 10 Bilder, 100 Bilder; Timeout/Retry.
- **Regression**: Scanner/Tagger-Ausgabe unverändert.
- **Logging**: Prüfen, dass keine Base64-Blobs in Logs sind.
