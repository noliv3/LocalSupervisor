# AGENTS – Supervisor (LocalSupervisor)

Dieses Dokument erklärt, **wie Supervisor als Gesamtsystem genutzt wird** und welche **Entwicklungs- und Betriebspläne** gelten.

## 1) Systembild (Portfolio statt Einzelmodul)

Supervisor ist eine lokale Medienplattform mit folgenden Bausteinen:

- **Web-UI** (Sichtung, Detail, Betrieb)
- **Zentrale Job-Queue** (planbare Hintergrundverarbeitung)
- **Worker-Module** (Scan, Media, Forge, Ollama)
- **Datenbank + Migrationen** (konsistente Datenhaltung)
- **Betriebs-/Admin-Skripte** (Diagnose, Cleanup, Konsistenz)

Wichtig: **Ollama und Forge sind optionale Module**. Der Kern bleibt ein vollständiges Medien- und Job-System.

## 2) Wie Supervisor genutzt wird

### Betriebsablauf (empfohlen)
1. Konfiguration anlegen und Pfade prüfen (`CONFIG/config.php`).
2. DB initialisieren/migrieren (`init_db.php`, `migrate.php`).
3. Web-UI starten (`WWW/`).
4. Jobs über CLI/UI einreihen.
5. Worker modular starten (Scan/Media/Forge/Ollama je nach Bedarf).
6. Health, Logs und Queue-Zustände regelmäßig prüfen.

### Modulnutzung in der Praxis
- **Scan**: Dateien erfassen und in den Katalog überführen.
- **Media**: Integritäts-, Hash- und Derivat-Jobs ausführen.
- **Forge**: Rezeptbasierte Verarbeitung orchestrieren.
- **Ollama**: Metadatenanreicherung über Stages (Caption bis Dupe-Hints/Embeddings/NSFW).

### Leitprinzipien im Betrieb
- Queue-Claiming atomar, keine Doppelverarbeitung.
- Stuck-Job-Recovery vor Batches.
- Lock + Heartbeat pro Worker.
- Interne Endpunkte nur intern abgesichert betreiben.
- Web-Pfad bleibt non-blocking: keine Worker-Spawns und keine Migrationen im laufenden HTTP-Request.

## 3) Verbindliche Invarianten

- Schemaänderungen nur über Migrationen; DB-Helfer synchron halten.
- README/AGENTS immer systemweit formulieren, nicht auf ein Teilmodul verengen.
- Keine Secrets/Keys/Base64-Mediendaten in Logs oder DB.
- Interne APIs nur mit `internal_api_key` + Loopback/Whitelist.
- Jede Feature-Änderung braucht ein README-Update im selben Change.

## 4) Dokumentationsstil (für Agenten)

- Änderungen klein und nachvollziehbar halten.
- README und AGENTS bei inhaltlichen Änderungen immer mitdenken.
- Keine Tests ausführen, sofern nichts anderes angeordnet ist.
- Keine großflächigen Umbauten ohne klaren Phasenplan.

## 5) Kurzfristiger Plan (Roadmap)

### Stabilität & Betrieb
- Worker-Recovery weiter vereinheitlichen.
- Lock-/Heartbeat-Transparenz im Monitoring verbessern.
- Fehlercodes zwischen Modulen konsistent halten.
- Persistente Worker-Services (Scan/Media/Forge/Library-Rename) als getrennte Hintergrundprozesse ausbauen.

### Produkt & UX
- UI-Übersichten für Queue- und Modulstatus vereinfachen.
- Dokumentation für typische Betriebsmodi (nur Scan, Scan+Forge, Vollbetrieb) schärfen.

### Daten & Qualität
- Konsistenzprüfungen weiter ausbauen.
- Auditierbarkeit von Jobketten verbessern.
- Metadatenfelder modular versionierbar halten.

## 6) Mittelfristiger Plan

- Bessere Presets für unterschiedliche Größenordnungen (klein/mittel/groß).
- Einheitliche Betriebsprofile für lokale Einzelinstanzen vs. Team-Setups.
- Klarere Trennung von optionalen Integrationen und Kernfunktionen in UI/Docs.

## 7) Scope-Hinweis

Supervisor ist als **vollständiges Portfolio** zu kommunizieren:
Medienverwaltung, Queueing, Worker-Orchestrierung, Web-Betrieb, Datenqualität und optionale KI/Forge-Integrationen.
Keine Dokumentation darf den Eindruck erwecken, das Projekt bestünde nur aus einem Einzelmodul.

## 8) Harte Betriebsbedingungen (nicht optional)

- CLI-Skripte aus `SCRIPTS/` sind ausschließlich für `PHP_SAPI === "cli"` gedacht; Browser/Web-SAPI-Aufrufe brechen absichtlich sofort ab.
- Nach Installation müssen Beispielkonfigurationen (mind. `CONFIG/config.example.php`) als produktive Dateien (`CONFIG/config.php`) vorhanden und angepasst sein.
- Fehlende Systemtools (`ffmpeg`, `ffprobe`, `magick`) sind kritische Blocker für Medienverarbeitung/Thumbnails (siehe Doctor).
- Konfigurationspfade wie `LIBRARY_PATH` und `THUMB_PATH` müssen existieren und schreibbar sein.
- DB-Initialisierung/Migration ist Pflicht; inkonsistente oder nicht initialisierte Schemata führen zu Fehlbetrieb.
- Ollama-Funktionen benötigen erreichbaren lokalen Dienst plus geladene, passende Modelle (z. B. Vision/Llava).
- Schreibrechte auf `LOG_PATH` und DB-Verzeichnis (insb. SQLite) sind zwingend für Logging/Persistenz.
