# Agent Notes

Scope: gesamtes Repository.

- Halte README und diese Datei aktuell, wenn Abläufe, Anforderungen oder Bedienhinweise geändert werden.
- Dashboard (`WWW/index.php`) dient als Einstieg für Scan-/Rescan-/Filesync-Starts, zeigt Kernstatistiken (Media/Prompts/Tags/Logs)
  und führt eine CLI-Referenz mit typischen Aufrufen; produktive neue CLIs (z. B. `prompts_rebuild_cli.php`) gehören in README und hier dokumentiert, auch wenn sie nicht vom Dashboard gestartet werden.
- Metadaten gehören in `media_meta`; erweitere Parser zentral in `sv_extract_metadata` statt Ad-hoc-Logik zu verteilen. Prompt-Kandidaten werden über `sv_collect_prompt_candidates`/`sv_select_prompt_candidate` priorisiert, bevor `sv_normalize_prompt_block` strukturiert nach `prompts` schreibt und Raw-Blöcke ablegt.
- Prompt-Parsing ist in `SCRIPTS/prompt_parser.php` sowie `sv_extract_metadata`/`sv_store_extracted_metadata` gebündelt; neue Quellen bitte dort ergänzen statt eigene Parser-CLIs anzulegen. Legacy-Helfer wie `exif_prompts_cli.php` sollten denselben Pfad nutzen und als solche gekennzeichnet bleiben.
- Metadaten/Prompts werden im Web read-only über `WWW/mediadb.php` (Liste) und `WWW/media_view.php` (Detail) angezeigt;
  neue UI-Felder bitte dort integrieren statt separate Viewer zu bauen.
- Konsistenzprüfungen laufen manuell über `php SCRIPTS/consistency_check.php` (optional `--repair=simple`); Ergebnisse landen in `LOGS/consistency_*.log` und in `consistency_log`, sobald Migration 002 eingespielt ist.
- Bevorzugte Referenzquelle für Schema/DB ist `DB/schema.sql`; bei Änderungen immer Daten- und Indextabellen dokumentieren.
- CLI-Flows basieren auf `SCRIPTS/scan_core.php` plus Wrappern; wenn du Erweiterungen baust, beschreibe den Aufrufpfad (CLI/Web) und Logging-Orte.
- Schema-Migrationen werden ausschließlich manuell per `php SCRIPTS/migrate.php` ausgeführt; neue Migrationen liegen in `SCRIPTS/migrations/` und tragen den Dateinamen auch als `version`-Eintrag in `schema_migrations`.
- Vermeide Try/Catch-Blöcke um Imports (`require`, `include`); Fehler sollen früh sichtbar bleiben.
- Sicherheitsrelevante Einstellungen (API-Keys, IP-Whitelist, Tokens) liegen in `CONFIG/config.php` und dürfen nicht mit Dummy-Werten in Commits geleakt werden.
- Betrieb / Heavy Tasks: Vor Migrationen oder Reparaturen zuerst `php SCRIPTS/db_backup.php` ausführen, dann `php SCRIPTS/migrate.php`, anschließend `php SCRIPTS/consistency_check.php` (report-only) und erst danach große Scans/Rescans/Filesync-Läufe (ggf. mit `--limit`/`--offset`).
- Kritische Writes aus dem Web (Scan/Rescan/Filesync, spätere Mutationen) müssen über geschützte Endpunkte mit `sv_require_internal_key` abgesichert werden; UI-Aktionen wie Ratings/Kommentare gelten als „low impact“ und dürfen ohne Key laufen, sollten aber entsprechend kommentiert sein.
- Neue Web-Parameter immer über Whitelists/Bereiche absichern; siehe `WWW/mediadb.php`, `WWW/media_view.php` und `WWW/thumb.php` für Beispiele.
- Ergänzende Härtungsnotizen stehen in `SECURITY_REVIEW.md`; halte README und diese Datei synchron, wenn Sicherheits- oder Betriebsabläufe angepasst werden.
- `SCRIPTS/paths_bootstrap.php` legt fehlende Symlinks im Webroot an (`bilder`, `fsk18`, `videos`, `videos18`), damit die aus der DB stammenden Pfade (`paths.*`) auch unter dem PHP-Built-in-Server erreichbar sind; `WWW/mediadb.php` ruft das Bootstrap bei jedem Request idempotent auf.

## Review-Status (Prompt/CLIs)
- Prompt-Kandidatenauswahl deckt EXIF-Kommentare, PNG-Text, parameterartige Strings und JSON-Blöcke (`sd-metadata`, `workflow`) ab; Priorisierung läuft über `sv_collect_prompt_candidates`/`sv_select_prompt_candidate`, Normalisierung über `sv_normalize_prompt_block` mit Ablage eines Raw-Blocks in `media_meta` und `prompts.source_metadata`.【F:SCRIPTS/prompt_parser.php†L185-L379】【F:SCRIPTS/scan_core.php†L510-L686】 Künftige Parser-Quellen bitte weiterhin dort integrieren.
- `prompts_rebuild_cli.php` arbeitet nur auf aktiven Bildern mit fehlenden Prompt-Kernfeldern und liest die Quelldatei erneut aus; vorhandene `media_meta`-Snapshots oder Historien werden dabei nicht ausgewertet, was für die Planung eines „Rebuild aus DB-Rohdaten“ zu berücksichtigen ist.【F:SCRIPTS/prompts_rebuild_cli.php†L42-L94】【F:SCRIPTS/scan_core.php†L616-L683】
- Dashboard-Buttons starten ausschließlich Scan/Rescan/Filesync; Rebuild, Backup, Migration, Konsistenzchecks oder EXIF-Füller bleiben manuell/CLI-only, bis eine UI-Erweiterung umgesetzt wird.【F:WWW/index.php†L29-L116】 Sensitivitäts-/Secrets-Checks für Metadaten sind aktuell nicht vorhanden; Raw-Blöcke werden unverändert gespeichert.【F:SCRIPTS/scan_core.php†L520-L686】
