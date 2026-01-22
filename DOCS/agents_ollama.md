# Agenten-Regeln – Ollama

## Prompt-Regeln
- Prompts **müssen** JSON-only Antworten verlangen (keine Zusatztexte, keine Markdown-Wrapper).
- Jede Prompt-Version erhält eine **stabile Versions-ID** (z. B. `vision_caption_v1`).
- Prompts dürfen **keine** Chain-of-Thought anfordern oder speichern.
- Feldnamen sind **deterministisch** und stabil (keine variierenden Keys je Antwort).

## JSON-Only Antworten
- Antworten enthalten **nur** das definierte JSON-Objekt.
- Keine Prosa, keine Erklärungen, keine Metadaten außerhalb des Vertrags.

## Logging-Regeln
- **Keine** Base64-Inhalte, Secrets oder vollständige Prompts in Logs schreiben.
- Logs enthalten nur gekürzte, safe Felder (z. B. Response-Preview, Modellname, Version).

## Versionierung
- Jede Auswertung speichert **model.name**, **model.digest** und **prompt_template_version**.
- Versionierte Ergebnisse sind idempotent; gleiche Version überschreibt nicht ohne Force-Flag.

## Deterministische Felder
- Bei deterministischen Runs (Temperature/Seed) dürfen Ausgaben nicht variieren.
- Pflichtfelder im JSON dürfen **nie** fehlen (auch bei Fehlern/Nullwerten).
