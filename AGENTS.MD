# Agent Notes

Scope: gesamtes Repository.

- Halte README und diese Datei aktuell, wenn Abläufe, Anforderungen oder Bedienhinweise geändert werden.
- Dashboard (`WWW/index.php`) dient als Einstieg für Scan-/Rescan-/Filesync-Starts, zeigt Kernstatistiken (Media/Prompts/Tags/Logs)
  und führt eine CLI-Referenz mit typischen Aufrufen; produktive neue CLIs dort mindestens dokumentieren.
- Metadaten gehören in `media_meta`; erweitere Parser zentral in `sv_extract_metadata` statt Ad-hoc-Logik zu verteilen.
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
