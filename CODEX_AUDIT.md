# CODEX AUDIT – SuperVisOr

## Repository-Inventar (kompakt)
- `CONFIG/`: Zentrale Konfiguration (DB, Pfade, Scanner/Security).
- `DB/`: Referenzschema (`schema.sql`).
- `SCRIPTS/`: Kernel-Logik (scan_core, prompt_parser, operations, logging, security, paths) plus CLIs und Migrationen.
- `WWW/`: Web-Dashboard, Medienlisten/-details, Streaming/Thumbnails.
- `LOGS/`, `TOOLS/`, `BACKUPS/` (durch Laufzeit gefüllt), `dependencies.txt`, Start-Skripte.

## Architekturmodell (Text-Diagramm)
```
[CLI/Web] -> operations.php -> scan_core.php
                        |-> prompt_parser.php
                        |-> logging.php / security.php / paths.php
                        |-> DB (schema.sql, migrations)

Web: index.php -> operations.php (geschützt) -> scan/rescan/filesync/prompts
     mediadb.php -> media_view.php -> media_stream.php/thumb.php (Pfadcheck)
```

## Risikoanalyse
- **Duplikate**: Legacy-Scanner (`scan_path.php`), leere Platzhalter (`sync_media_cli.php`) weiter vorhanden; Nutzung vermeiden.
- **Legacy**: Parser-/CLI-Helfer mit älteren Pfaden existieren; neue Funktionen müssen `scan_core`/`prompt_parser` nutzen.
- **Prompt-/Tag-Lücken**: Keine Prompt-Historie; Rebuild liest nicht aus vorhandenen `media_meta`; Tag-Locks schützen manuelle Anpassungen, aber automatische Qualitätssicherung fehlt.
- **Web-UI-Lücken**: UI funktional, aber ohne moderne UX/JS; kein fein granularer Rollenschutz neben Internal-Key/IP-Whitelist für Writes.
- **Security-Grenzen**: Pfadvalidierung kritisch in `media_stream.php`/`thumb.php`; Internal-Key-Flow zwingend; Rate-Limits fehlen.

## Empfohlene Strukturregeln
- Einhaltung der zentralen Pfade: neue Parser/Flows in `prompt_parser` und `scan_core` integrieren.
- Keine automatischen Migrationen oder Schemaänderungen außerhalb `migrate.php`.
- Web-Schreibpfade nur über `sv_require_internal_access` absichern; Pfadprüfungen nicht umgehen.
- Legacy-Dateien nur entfernen/ersetzen, wenn explizit beauftragt.

## Change-Tracking / Status
- Backend (Scan/Prompt/Tagging/Consistency) als gehärtete Basis bestätigt.
- UI-Fixes/Modernisierung pending (Phase 3).
- Regeneration/Delete-Mechanik geplant: Rebuild aus `media_meta` und gesteuerte Lösch-/Qualitätsprozesse fehlen noch.
