# SuperVisOr – lokale Bild/Video-Verwaltung

SuperVisOr ist ein PHP-basiertes Werkzeug, um große lokale Sammlungen von Bildern und Videos einheitlich zu erfassen, zu scannen (NSFW/Tags) und für spätere Reproduktionen via FORGE vorzubereiten. Die Anwendung kombiniert eine Weboberfläche mit CLI-Tools für Batch-Jobs.

## Systemvoraussetzungen
- PHP 8.1+ mit PDO (SQLite oder MySQL/MariaDB), JSON, mbstring, fileinfo sowie Bildbibliothek (gd oder imagick) und optional EXIF und cURL für API-Aufrufe.【F:dependencies.txt†L2-L14】
- Datenbank: SQLite (supervisor.sqlite) oder alternativ MySQL/MariaDB.【F:dependencies.txt†L16-L18】
- Externe Tools optional: ffmpeg (Videometadaten/Thumbnails) und exiftool (erweiterte Metadaten).【F:dependencies.txt†L20-L22】
- Scanner- und FORGE-APIs mit Token-Authentifizierung; IP-Whitelist und interne API-Keys sind vorgesehen.【F:dependencies.txt†L24-L39】【F:CONFIG/config.php†L36-L51】

## Konfiguration
Die zentrale Konfiguration liegt in `CONFIG/config.php` und definiert:
- Datenbank-DSN samt PDO-Optionen (standardmäßig SQLite im DB-Verzeichnis).【F:CONFIG/config.php†L6-L15】
- Zielpfade für sichere/NSFW-Bilder und -Videos sowie Log- und Temp-Verzeichnisse.【F:CONFIG/config.php†L17-L28】
- Pfade zu optional mitgelieferten ffmpeg-/exiftool-Binaries.【F:CONFIG/config.php†L30-L34】
- Scanner-Einstellungen (Base-URL, Token, Timeout, NSFW-Schwelle).【F:CONFIG/config.php†L36-L45】
- Sicherheitsparameter wie interner API-Key und IP-Whitelist.【F:CONFIG/config.php†L48-L51】

## Verzeichnisstruktur
- `CONFIG/` – globale Einstellungen inklusive DB-DSN und Scanner/Forge-Security-Parameter.【F:CONFIG/config.php†L2-L51】
- `DB/schema.sql` – Referenzschema für sämtliche Tabellen und Indizes.【F:DB/schema.sql†L3-L215】
- `SCRIPTS/` – CLI-Tools und gemeinsame Scan-Logik (`scan_core.php`).【F:SCRIPTS/scan_core.php†L4-L118】【F:SCRIPTS/scan_path_cli.php†L4-L75】
- `WWW/` – Webkomponenten (Dashboard, Thumbnails).【F:WWW/index.php†L1-L164】
- `TOOLS/` – optionaler Ablageort für ffmpeg/exiftool (nicht im Repo enthalten).【F:CONFIG/config.php†L30-L34】

## Datenbankschema (bestätigtes REFERENZSCHEMA_V1)
Alle Tabellen sind in `DB/schema.sql` definiert und entsprechen dem aktuellen Live-Schema:
- `media`: Pfad, Typ (image/video), Quelle, Maße, Videometadaten (duration/fps/filesize) sowie Basis-Metadaten wie Hash, Zeitstempel, Rating/NSFW-Flags, Parent-Verknüpfung und Status; Indizes auf Hash, Quelle, Rating, Status und Importzeit.【F:DB/schema.sql†L3-L37】
- `tags` & `media_tags`: Schlagwortverwaltung mit Lock-Flag und Konfidenz; Join-Tabelle mit PK (media_id, tag_id) plus Indizes auf beide Seiten.【F:DB/schema.sql†L41-L61】
- `scan_results`: Historie pro Scannerlauf inkl. NSFW-Score, Flags und Roh-JSON; Indizes auf media_id und scanner.【F:DB/schema.sql†L65-L81】
- `prompts`: Prompt-/Parameter-Archiv pro Medium (positive/negative Prompts, Modell, Sampler, CFG, Seed, Auflösung, Scheduler, JSON-Felder).【F:DB/schema.sql†L85-L107】
- `media_meta`: Freie Metadatenquelle für EXIF/XMP/PNG-Text, ffmpeg-Ausgaben oder Parser-Extrakte (z. B. forge/comfy/import); jede Zeile enthält `source` als Namespace, `meta_key`, `meta_value` und Timestamp pro `media_id`.【F:DB/schema.sql†L200-L211】
- `jobs`: FORGE-Aufträge inkl. Status, Zeitstempel, Request/Response-JSON und Fehlertext; Indizes auf Status und media_id.【F:DB/schema.sql†L111-L131】
- `collections` & `collection_media`: Virtuelle Ordner mit Many-to-Many-Beziehung; PK (collection_id, media_id) plus Indizes auf beide Spalten.【F:DB/schema.sql†L135-L154】
- `import_log`: Import-Historie mit Status und Zeitstempel, indiziert nach Status/created_at.【F:DB/schema.sql†L158-L170】
- `schema_migrations`: Versionierungstabelle für manuelle Migrationen mit `version`, `applied_at` und optionaler Beschreibung.【F:DB/schema.sql†L172-L178】
- `audit_log`: Audit-Trail für sicherheitsrelevante Aktionen inklusive IP/Key-Markern.【F:DB/schema.sql†L193-L202】

## Schema-Migrationen
- Neue Migrationen werden als Dateien `NNN_name.php` im Ordner `SCRIPTS/migrations/` abgelegt; der Dateiname (ohne `.php`) muss exakt dem `version`-Eintrag entsprechen und ein Array mit `version`, `description` und einer ausführbaren `run`-Funktion zurückgeben.【F:SCRIPTS/migrations/001_initial_schema.php†L1-L29】
- Ausführung erfolgt manuell über `php SCRIPTS/migrate.php`; das Skript legt bei Bedarf `schema_migrations` an, sortiert alle Dateien, führt nur fehlende Versionen aus und trägt sie nach Erfolg in die Tabelle ein.【F:SCRIPTS/migrate.php†L1-L113】【F:SCRIPTS/migrate.php†L141-L173】
- Die Baseline `001_initial_schema` markiert das bestätigte REFERENZSCHEMA_V1 und fügt lediglich einen Eintrag in `schema_migrations` hinzu, falls er noch fehlt.【F:SCRIPTS/migrations/001_initial_schema.php†L8-L29】
- `003_add_runtime_indexes` ergänzt einen Index auf `media.type`, der Scan- und Rescan-Filter beschleunigt.【F:SCRIPTS/migrations/003_add_runtime_indexes.php†L1-L37】【F:DB/schema.sql†L21-L35】
- `004_add_audit_log` legt ein Audit-Log für sicherheitsrelevante Aktionen an.【F:SCRIPTS/migrations/004_add_audit_log.php†L1-L37】【F:DB/schema.sql†L193-L202】
- Automatische Migrationen in Web- oder CLI-Scan-Skripten sind nicht vorgesehen; Änderungen müssen immer bewusst über den Runner gestartet werden.【F:SCRIPTS/migrate.php†L1-L6】

## Konsistenzprüfungen (Schritt 3)
- Die Tabelle `consistency_log` dokumentiert Funde der Prüfläufe (Migration `002_add_consistency_log`, siehe `DB/schema.sql`).【F:DB/schema.sql†L184-L190】【F:SCRIPTS/migrations/002_add_consistency_log.php†L1-L37】
- CLI-Tool: `php SCRIPTS/consistency_check.php` (nur Bericht) oder `php SCRIPTS/consistency_check.php --repair=simple` (inkl. einfacher Reparaturen in Join-Tabellen bzw. Status-Flag `missing`).【F:SCRIPTS/consistency_check.php†L4-L285】
- Ergebnisse erscheinen auf STDOUT, werden in `LOGS/consistency_*.log` gespeichert und – nach eingespielter Migration – in `consistency_log` geschrieben.【F:SCRIPTS/consistency_check.php†L51-L100】【F:SCRIPTS/consistency_check.php†L112-L285】

## Prompt-Pipeline & Arbeitsabläufe
- **Erstimport & Scan**: `scan_path_cli.php` lädt Konfiguration, verbindet mit der DB und ruft `sv_run_scan_path` auf, um einen angegebenen Ordner rekursiv zu verarbeiten. Optional begrenzt `--limit=N` die Anzahl der verarbeiteten Dateien pro Lauf.【F:SCRIPTS/scan_path_cli.php†L12-L81】【F:SCRIPTS/scan_core.php†L409-L495】 Die zentrale Logik (`scan_core.php`) erkennt Dateityp, berechnet Hash/Metadaten, ruft den konfigurierten Scanner via HTTP auf, verschiebt Dateien in SFW/NSFW-Zielpfade und schreibt Datensätze in `media`, `scan_results`, `tags/media_tags` sowie `import_log`. Dabei werden Prompts und Zusatzmetadaten nach `media_meta` übernommen (EXIF/PNG-Text, ffmpeg, Parser).【F:SCRIPTS/scan_core.php†L486-L620】
- **Rescan bestehender Medien**: `rescan_cli.php` nutzt `sv_run_rescan_unscanned`, um bereits importierte, aber ungescannte Medien erneut durch den Scanner zu schicken und Status/Ratings zu aktualisieren. Mit `--limit` und `--offset` lassen sich Teilmengen stapelweise bearbeiten. Fehlende Metadaten/Prompts werden via `sv_extract_metadata` nachgezogen und in `media_meta` abgelegt.【F:SCRIPTS/rescan_cli.php†L4-L87】【F:SCRIPTS/scan_core.php†L620-L748】
- **Filesystem-Sync**: `filesync_cli.php` führt `sv_run_filesync` aus, um die Existenz aller `media.path`-Einträge zu prüfen und den Status auf `active`/`missing` zu setzen; `--limit` und `--offset` erlauben Batches.【F:SCRIPTS/filesync_cli.php†L4-L78】【F:SCRIPTS/scan_core.php†L710-L789】
- **Metadaten-Inspektor**: `meta_inspect.php` liefert eine reine Textübersicht der gespeicherten Prompts und `media_meta`-Einträge pro Medium; `--limit=N` steuert die Anzahl der Datensätze.【F:SCRIPTS/meta_inspect.php†L1-L101】 Webseitig sind dieselben Informationen über `mediadb.php` → `media_view.php?id=…` read-only einsehbar.【F:WWW/mediadb.php†L286-L343】【F:WWW/media_view.php†L60-L208】
- **Automatische Prompt-Normalisierung**: `sv_extract_metadata` sammelt EXIF-Kommentare (UserComment/ImageDescription/XpComment/Comment) sowie PNG-/JSON-Quellen (`parameters`, `prompt`/`Negative prompt`, `sd-metadata`, `workflow`) und wählt via `sv_collect_prompt_candidates` und `sv_select_prompt_candidate` einen bevorzugten Block aus, bevor `sv_normalize_prompt_block` ihn strukturiert in `prompts` einträgt und Raw-Blöcke zusätzlich in `media_meta` ablegt.【F:SCRIPTS/scan_core.php†L512-L686】【F:SCRIPTS/prompt_parser.php†L185-L419】 Kandidaten werden nach Gewichtung priorisiert (Parameters > sd-metadata/workflow > kombinierter Prompt > EXIF-Texte > Fallback-Rohblock), sodass Parser- und EXIF-Quellen konsistent landen.【F:SCRIPTS/prompt_parser.php†L279-L419】
- **Prompt-Rebuild & Legacy-Importe**: `prompts_rebuild_cli.php` nutzt dieselbe Pipeline, um aktive Bilder mit fehlenden oder unvollständigen Prompt-Feldern erneut direkt von der Quelldatei zu parsen (Limit/Offset steuerbar); vorhandene `media_meta`-Einträge werden dabei aktuell nicht ausgewertet.【F:SCRIPTS/prompts_rebuild_cli.php†L42-L94】【F:SCRIPTS/scan_core.php†L510-L686】 `exif_prompts_cli.php` bleibt als Legacy-Fallback erhalten, ruft aber ebenfalls den zentralen Parser über `sv_extract_metadata` auf.【F:SCRIPTS/exif_prompts_cli.php†L4-L80】

## Review-Status Prompt-/CLI-Landschaft (Abgleich mit 3-Spalten-Tabelle)
- **Abdeckung aktueller Parser-Quellen**: Die Prompt-Kandidatensuche berücksichtigt EXIF-Kommentare, PNG-Textfelder, parameterartige Strings sowie JSON-Varianten (`sd-metadata`, `workflow` etc.) und wählt per Prioritätenliste den bestbewerteten Block aus.【F:SCRIPTS/prompt_parser.php†L185-L379】 Roh-Blöcke werden parallel in `media_meta` gespeichert, was spätere Analysen/Rescans absichert.【F:SCRIPTS/scan_core.php†L510-L686】
- **Rebuild-Verhalten**: `prompts_rebuild_cli.php` verarbeitet nur aktive Bilder mit fehlenden Kernfeldern (z. B. Prompt, Model, Steps, Seed, Size) und liest die Metadaten erneut aus der Datei; ein Rebuild auf Basis vorhandener `media_meta`-Snapshots oder eine Prompt-Historie ist noch nicht implementiert.【F:SCRIPTS/prompts_rebuild_cli.php†L42-L94】【F:SCRIPTS/scan_core.php†L616-L683】
- **Dashboard-Reichweite**: Das Web-Dashboard startet ausschließlich Scan-, Rescan- und Filesync-CLIs; Rebuild, Backup, Migration, Konsistenzprüfungen oder EXIF-Importe müssen weiterhin manuell per CLI erfolgen.【F:WWW/index.php†L29-L116】
- **Sicherheits-/Sensitivitätsprüfung**: Metadaten und Raw-Prompt-Blöcke werden unverändert in `media_meta` bzw. `prompts.source_metadata` abgelegt; es gibt keine Filterung oder Kennzeichnung sensibler Schlüssel/Werte, wie in der „Brauchen wir dringend“-Spalte gefordert.【F:SCRIPTS/scan_core.php†L520-L686】【F:SCRIPTS/prompt_parser.php†L214-L258】

## CLI-Landschaft (Produktiv, Inspektor, Legacy)
- **Produktiv**: `scan_path_cli.php` (Import/Scan), `rescan_cli.php` (Rescan unvollständiger Medien), `filesync_cli.php` (Status aktiv/missing), `prompts_rebuild_cli.php` (Prompt-Felder nachziehen), `cleanup_missing_cli.php` (Entfernen als missing markierter Medien), `migrate.php` (Schema), `db_backup.php` (Backups), `consistency_check.php` (report/repair) und `init_db.php` (Initialschema).【F:SCRIPTS/scan_path_cli.php†L4-L81】【F:SCRIPTS/rescan_cli.php†L4-L87】【F:SCRIPTS/filesync_cli.php†L4-L78】【F:SCRIPTS/prompts_rebuild_cli.php†L4-L95】【F:SCRIPTS/cleanup_missing_cli.php†L4-L98】【F:SCRIPTS/migrate.php†L1-L173】【F:SCRIPTS/db_backup.php†L1-L118】【F:SCRIPTS/consistency_check.php†L4-L285】【F:SCRIPTS/init_db.php†L1-L79】
- **Inspektoren/Metatools**: `meta_inspect.php`, `db_inspect.php`, `show_prompts_columns.php` und `exif_prompts_cli.php` liefern Read-only-Einblicke oder ergänzende Prompt-Füller ohne Produktionspfad zu verändern.【F:SCRIPTS/meta_inspect.php†L1-L101】【F:SCRIPTS/db_inspect.php†L1-L107】【F:SCRIPTS/show_prompts_columns.php†L1-L37】【F:SCRIPTS/exif_prompts_cli.php†L4-L90】
- **Legacy/Deprecation**: `scan_path.php` (älterer Direkt-Scanner ohne Scan-Core) liegt weiterhin im Repo, wird aber vom Dashboard oder den aktuellen Wrappern nicht mehr genutzt; `sync_media_cli.php` ist eine leere Platzhalter-Datei.【F:SCRIPTS/scan_path.php†L1-L150】【F:SCRIPTS/sync_media_cli.php†L1-L1】

## Betrieb / Heavy Tasks
- Vor Migrationen oder Reparaturläufen immer ein manuelles Backup ziehen: `php SCRIPTS/db_backup.php` legt Kopien unter `BACKUPS/` (oder `paths.backups`) sowie ein Log unter `LOGS/` ab.【F:SCRIPTS/db_backup.php†L1-L98】
- Empfohlene Reihenfolge vor größeren Änderungen: `php SCRIPTS/db_backup.php` → `php SCRIPTS/migrate.php` → `php SCRIPTS/consistency_check.php` (report-only) → anschließend erst `scan_path_cli.php`, `rescan_cli.php` oder `filesync_cli.php` mit optionalen Limits starten.
- Beispielaufrufe für Batches: `php SCRIPTS/scan_path_cli.php "D:\\Import" --limit=250`, `php SCRIPTS/rescan_cli.php --limit=100 --offset=200`, `php SCRIPTS/filesync_cli.php --limit=500`.

## Weboberfläche
Das Dashboard (`WWW/index.php`) stellt eine einfache Übersicht bereit: PDO-Verbindung über `CONFIG/config.php`, Ausgabe der vorhandenen DB-Tabellen (SQLite) sowie Formulare, um Scan-, Rescan- und Filesync-CLI-Skripte im Hintergrund zu starten und Log-Dateien abzulegen.【F:WWW/index.php†L6-L354】
Unterhalb der Formulare zeigt das Dashboard eine komprimierte Statistik über Medien (Typ/Status/Rating/NSFW), Prompts, Tags, Scan-Resultate, media_meta sowie Import-/Job-Logs und listet eine CLI-Referenz mit Kategorien und Beispielaufrufen für die wichtigsten Skripte.【F:WWW/index.php†L356-L502】

- `mediadb.php`: Listenansicht mit Filtern nach Typ (Bild/Video), Prompt-Flag, Metadaten-Flag, Status, Mindest-Rating und Pfad-Substring; NSFW lässt sich über `adult=1` einblenden. Zeigt Typ-Badges, Prompt-/Metadaten-Indikatoren und Links zur Detailseite/Originalpfad.【F:WWW/mediadb.php†L1-L377】
  Beim Start werden Symlinks im Webroot (`WWW/bilder`, `WWW/fsk18`, `WWW/videos`, `WWW/videos18`) gegenüber den in `paths.*` konfigurierten Quellverzeichnissen angelegt, sodass auch Windows-Pfade über den eingebauten Webserver erreichbar sind.【F:WWW/mediadb.php†L1-L52】【F:SCRIPTS/paths_bootstrap.php†L1-L38】
- `media_view.php`: Detailansicht für ein Medium mit Thumbnail bzw. Video-Platzhalter, Prompt/Negative Prompt, Kern-Metadaten aus `media` (inkl. Dauer/FPS/Dateigröße) sowie gruppierter Anzeige der `media_meta`-Einträge nach `source`. Navigation zu Vorher/Nachher berücksichtigt das FSK18-Flag.【F:WWW/media_view.php†L1-L208】【F:WWW/media_view.php†L214-L311】
- Filter-/ID-Parameter der Webansichten werden serverseitig defensiv normalisiert (Enum-Whitelists, Integer-Bounds, begrenzte Suchstring-Längen), um Missbrauch durch extreme oder ungültige Werte abzufedern.【F:WWW/mediadb.php†L1-L120】【F:WWW/media_view.php†L1-L119】【F:WWW/thumb.php†L1-L80】

## Sicherheit und Betrieb
- Web-Schreibzugriffe auf Scanner/Filesync/Rescan oder spätere Mutationen müssen den `internal_api_key` über den Header `X-Internal-Key` oder den Parameter `internal_key` mitschicken; ohne Schlüssel antworten geschützte Endpunkte mit HTTP 403.【F:SCRIPTS/security.php†L32-L83】
- Optional kann eine `ip_whitelist` gesetzt werden, um Web-Requests zusätzlich nach Quelle einzuschränken; CLI-Tools laufen grundsätzlich ohne Schlüssel, da sie lokal ausgeführt werden.【F:SCRIPTS/security.php†L14-L83】【F:CONFIG/config.php†L41-L52】
- `sv_require_internal_key` bündelt die Prüfungen und wird an kritischen Web-Einstiegspunkten wie dem Dashboard verwendet.【F:WWW/index.php†L5-L94】【F:SCRIPTS/security.php†L32-L64】
- Das Audit-Log protokolliert sicherheitsrelevante Aktionen wie Migrationen, Backups, Konsistenz-Reparaturen oder Web-Starts von Scan/Rescan/Filesync samt IP/Key-Markern.【F:SCRIPTS/migrate.php†L1-L94】【F:SCRIPTS/db_backup.php†L1-L118】【F:SCRIPTS/consistency_check.php†L1-L116】【F:WWW/index.php†L5-L94】【F:DB/schema.sql†L193-L202】
- Dateipfade und Log-Verzeichnisse in `CONFIG/config.php` an die Zielumgebung anpassen; Standardwerte zeigen auf Windows-Laufwerke.【F:CONFIG/config.php†L17-L34】
- ffmpeg/exiftool sind optional und müssen separat installiert oder in `TOOLS/` bereitgestellt werden.【F:dependencies.txt†L20-L22】【F:CONFIG/config.php†L30-L34】
- Ergänzende Sicherheitsbewertung und Härtungs-Prioritäten siehe `SECURITY_REVIEW.md` (Angriffsflächen, Internal-Key/IP-Whitelist, Audit-Abdeckung, Schutzempfehlungen).

