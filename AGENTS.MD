# Agent Notes

Scope: gesamtes Repository.

- Halte README und diese Datei aktuell, wenn Abläufe, Anforderungen oder Bedienhinweise geändert werden.
- Bevorzugte Referenzquelle für Schema/DB ist `DB/schema.sql`; bei Änderungen immer Daten- und Indextabellen dokumentieren.
- CLI-Flows basieren auf `SCRIPTS/scan_core.php` plus Wrappern; wenn du Erweiterungen baust, beschreibe den Aufrufpfad (CLI/Web) und Logging-Orte.
- Schema-Migrationen werden ausschließlich manuell per `php SCRIPTS/migrate.php` ausgeführt; neue Migrationen liegen in `SCRIPTS/migrations/` und tragen den Dateinamen auch als `version`-Eintrag in `schema_migrations`.
- Vermeide Try/Catch-Blöcke um Imports (`require`, `include`); Fehler sollen früh sichtbar bleiben.
- Sicherheitsrelevante Einstellungen (API-Keys, IP-Whitelist, Tokens) liegen in `CONFIG/config.php` und dürfen nicht mit Dummy-Werten in Commits geleakt werden.
