# SuperVisOr CLI-Aufräumplan (Entwurf)

## Bestand und Empfehlungen
- **Beibehalten (Core/Wrapper):** `scan_path_cli.php`, `rescan_cli.php`, `filesync_cli.php` (nutzen `scan_core.php`), `migrate.php`, `db_backup.php`, `consistency_check.php`, `cleanup_missing_cli.php`, `init_db.php`.
- **Metadaten-/Prompt-Tools (optional, behalten):** `exif_prompts_cli.php`, `meta_inspect.php`, `db_inspect.php`, `show_prompts_columns.php`.
- **Legacy/Deprecated:** `scan_path.php` (älterer Scanner neben `scan_core.php`), `sync_media_cli.php` (leer) → zur Entfernung oder klaren Deprecation markieren.

## Zielbild CLI-Bedienung
- Einheitliche Parameter-Konvention: `path` (Pflicht bei Import), `--limit`, `--offset`, `--dry-run` (wo sinnvoll), `--repair` für Reparaturläufe.
- Klare Rollen:
  - **Import/Rescan/Filesync:** zentrale Batch-Jobs über `scan_core`-Wrapper.
  - **Wartung:** `db_backup.php` → `migrate.php` → `consistency_check.php` (optional `--repair=simple`) → `cleanup_missing_cli.php`.
  - **Metadatenpflege:** `exif_prompts_cli.php` nach Bedarf; reine Inspektoren bleiben read-only.

## Dashboard-Empfehlungen
- Buttons für: Scan, Rescan, Filesync (bestehend). Optional ergänzt um: Backup, Migration, Konsistenzcheck (report-only), Cleanup missing, EXIF-Prompt-Import (mit Warnhinweis/Limits).
- CLI-only belassen: `init_db.php` (Setup), irreversible Reparaturen (`consistency_check.php --repair`, `cleanup_missing_cli.php` falls kritisch) je nach Freigabe.

## Orchestrierungsidee
1) `db_backup.php` (Pflicht vor Änderungen).
2) `migrate.php` (Schema-Updates).
3) `consistency_check.php` (erst report, optional `--repair=simple`).
4) `cleanup_missing_cli.php` (nach bestätigten Missing-Einträgen).
5) Produktivläufe: `scan_path_cli.php` (Pfad, optional `--limit`), `rescan_cli.php` (`--limit/--offset`), `filesync_cli.php` (`--limit/--offset`).
6) Optional: `exif_prompts_cli.php` für Prompt-/Meta-Füllung.

## Hinweise
- Dashboard-Starts nutzen aktuell `cmd /C start` (Windows). Für Linux/macOS ggf. plattformneutrale Variante ergänzen.
- Sicherheitskritische Web-Starts nur mit `sv_require_internal_key` und Audit-Log nutzen; CLI bleibt intern.
