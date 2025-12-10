# SuperVisOr – gehärtete Übersicht

## Projektüberblick
SuperVisOr ist ein PHP-basiertes Werkzeug für das lokale Management großer Bild- und Video-Sammlungen. Es kombiniert Scanner-gestützten Import, Prompt-Pipeline, Tagging und eine Weboberfläche zur Anzeige und Steuerung wiederkehrender Abläufe. Ziel ist eine konsistente Datenbasis aus Dateien, Prompts und Metadaten ohne Abhängigkeit von Cloud-Diensten.

## Systemarchitektur
- **Kernel-Logik (SCRIPTS/)**: Zentrale Funktionen in `scan_core` (Dateierkennung, Hashing, Pfadvalidierung, Logging, DB-Writes), `prompt_parser` (EXIF/PNG/JSON-Kandidaten sammeln, priorisieren, normalisieren), `operations` (einheitliche Einstiegspunkte für Scan/Rescan/Filesync/Prompt-Rebuild/Konsistenz), `logging` (kanalisiertes Logging mit Rotation), `security` (Internal-Key + IP-Whitelist), `paths` (Pfadkonfiguration und Validierung).
- **Webschicht (WWW/)**: Dashboard `index.php` (Formulare für Scan/Rescan/Filesync/Prompt-Rebuild/Konsistency, Statistiken, CLI-Referenz), Listenansicht `mediadb.php`, Detail `media_view.php`, Streaming `media_stream.php`, Thumbnails `thumb.php`.
- **Persistenz (DB/)**: SQLite/MySQL-Schema aus `DB/schema.sql`, Migrationen in `SCRIPTS/migrations/`, Konfiguration in `CONFIG/config.php`.

### Datenbankschema (Strukturüberblick)
| Tabelle | Zweck | Kernfelder/Indizes |
| --- | --- | --- |
| media | Basis-Metadaten zu Dateien (Typ, Hash, Status, Größe, Dauer/FPS, Rating/NSFW) | Indizes auf Hash, Typ, Status, Rating, Importzeit |
| scan_results | Scanner-Historie pro Lauf inkl. NSFW-Werte und Roh-JSON | Indizes auf media_id, scanner |
| tags / media_tags | Schlagworte mit Lock/Confidence, Join-Tabelle media↔tag | PK auf (media_id, tag_id); Indizes je Seite |
| prompts | Normalisierte Prompts und Parameter (positive/negative, Modell, Sampler, CFG, Seed, Größe, JSON) | Indizes auf media_id |
| media_meta | Freie Metadatenquellen (EXIF/PNG/ffmpeg/Parser) | Indizes auf media_id, source |
| collections / collection_media | Virtuelle Sammlungen | PK (collection_id, media_id) |
| jobs | Forge-Aufträge inkl. Request/Response-JSON | Indizes auf status, media_id |
| import_log | Importstatus pro Datei | Indizes auf status, created_at |
| consistency_log | Ergebnisprotokoll der Konsistenzchecks | Indizes auf status, checked_at |
| schema_migrations | Manuelle Migrationen | PK version |
| audit_log | Security-relevante Aktionen (IP/Key/Action) | Indizes auf created_at |

## Funktionen und Workflows
- **Scan**: `scan_core` identifiziert Typ (Bild/Video), berechnet Hash, extrahiert Basis-Metadaten, ruft den konfigurierten Scanner via HTTP, verschiebt Dateien in die gültigen SFW/NSFW-Zielpfade und schreibt `media`, `scan_results`, `tags/media_tags`, `import_log` sowie `media_meta`/`prompts`.
- **Rescan**: Sendet vorhandene Medien erneut an den Scanner, aktualisiert Status/Ratings/NSFW und füllt fehlende Metadaten nach.
- **Filesync**: Prüft die Existenz der `media.path`-Einträge und setzt Status `active`/`missing`; optional in Batches.
- **Prompt-Extraktion & -Normalisierung**: Kandidaten aus EXIF-Kommentaren, PNG-Text, Parameter-Strings und JSON-Blöcken werden gesammelt, gewichtet und in `prompts` strukturiert; Raw-Blöcke landen parallel in `media_meta`.
- **Prompt-Rebuild**: Liest aktive Medien mit fehlenden Kernfeldern erneut von der Quelldatei und wendet die Prompt-Pipeline an (keine Auswertung bestehender `media_meta`-Snapshots).
- **Tag-Pipeline**: Scanner liefert Tags/Confidence; Persistenz erfolgt in `tags`/`media_tags` mit Lock-Flag, um manuelle Korrekturen zu schützen.
- **Medienanzeige**: `mediadb.php` filtert/zeigt Liste, `media_view.php` zeigt Details inkl. Metadaten/Prompts, `media_stream.php`/`thumb.php` streamen geprüfte Pfade.
- **Einzel-Rebuild / logisches Löschen**: In `media_view.php` können einzelne Medien erneut durch die Prompt-Pipeline geschickt oder als `missing` markiert werden (keine Dateilöschung, Status-Umschaltung über `operations.php`).
- **Sicherheitsmodell**: Schreibende Webaktionen verlangen Internal-Key + IP-Whitelist; Pfadvalidierung verhindert Symlinks/Webroot-Bypass; Audit-Log dokumentiert kritische Operationen.

## Installation / Setup
- **Voraussetzungen**: PHP 8.1+ mit PDO (SQLite/MySQL), JSON, mbstring, fileinfo, gd/imagick; optional ffmpeg (Video/Thumbnails), exiftool (Metadaten). Datenbank per SQLite-File oder MySQL/MariaDB.
- **Konfiguration**: `CONFIG/config.php` definiert DB-DSN, Pfade für SFW/NSFW-Bild/Video, Logs/Temp/Backups, optionale Tool-Pfade (ffmpeg/exiftool), Scanner-Endpunkte (Base-URL, Token, Timeouts, NSFW-Schwelle), Sicherheitsparameter (internal_api_key, ip_whitelist).
- **Serverstart**: PHP-Builtin-Server oder Webserver auf `WWW/` zeigen; CLI-Aufrufe von `SCRIPTS/` benötigen PHP-CLI und Zugriff auf `CONFIG/config.php`.
- **Scanner-Verbindung**: `scan_core` ruft den konfigurierten Scanner via HTTP; Token/URL in `CONFIG/config.php` pflegen und Netzwerkzugriff sicherstellen.

## CLI- und Web-Operations
> Hinweis: Alle CLI-Kommandos laufen ausschließlich über `SCRIPTS/`; im `WWW/`-Verzeichnis existieren keine parallelen CLI-Dateien mehr (Legacy-Wrapper wurden entfernt). Deployments sollten sicherstellen, dass nur das bereinigte `WWW/`-Set auf dem Webserver liegt.
| Befehl/Endpoint | Zweck | Wichtige Parameter |
| --- | --- | --- |
| `php SCRIPTS/scan_path_cli.php <path> [--limit=N] [--offset=N]` | Erstimport eines Verzeichnisses, rekursiv | Pfad zur Quelle; Limits für Batches |
| `php SCRIPTS/rescan_cli.php [--limit=N] [--offset=N]` | Rescan vorhandener Medien | Batch-Steuerung |
| `php SCRIPTS/filesync_cli.php [--limit=N] [--offset=N]` | Status-Sync gegen Dateisystem | Batch-Steuerung |
| `php SCRIPTS/prompts_rebuild_cli.php [--limit=N] [--offset=N]` | Prompt-Rebuild aktiver Medien mit fehlenden Feldern | Batch-Steuerung |
| `php SCRIPTS/consistency_check.php [--repair=simple] [--limit=N] [--offset=N]` | Konsistenzprüfungen, optional einfache Reparaturen | Repair-Modus, Batches |
| `php SCRIPTS/db_backup.php` | Manuelles Backup der DB | Zielpfade aus `paths.backups` |
| `php SCRIPTS/migrate.php` | Führt fehlende Migrationen aus | Keine Auto-Migrationen |
| `php SCRIPTS/meta_inspect.php [--limit=N] [--offset=N]` | Text-Inspektor für Prompts/Metadaten | Batches |
| `WWW/index.php` | Dashboard: Start Scan/Rescan/Filesync/Prompt-Rebuild/Konsistency, Statistiken, CLI-Referenz | Internal-Key + IP-Whitelist für Write-Actions |
| `WWW/mediadb.php` | Listenansicht mit Filtern | type, prompt, meta, status, rating_min, path_substring, adult |
| `WWW/media_view.php?id=...` | Detailansicht eines Mediums | id (Integer), optional adult |
| `WWW/media_stream.php?path=...` | Streamt Originaldateien nach Pfad-Validierung | path (unterhalb erlaubter Roots) |
| `WWW/thumb.php?path=...` | Thumbnails nach Pfad-Validierung | path (unterhalb erlaubter Roots) |

## Bekannte Einschränkungen / Offene Baustellen
- Prompt-Historie fehlt; Raw-Blöcke werden zwar gespeichert, aber Historisierung/Versionierung der Prompts ist nicht vorhanden.
- Automatische Regeneration aus bestehenden `media_meta`-Snapshots existiert nicht; Rebuild liest immer von der Quelldatei.
- Delete-/Qualitätsmechanik (automatisches Löschen/Retagging) ist nicht implementiert; Status-Flag `missing` ersetzt Löschungen.
- UI-Modernisierung steht aus: Dashboard/Listen/Detail sind funktional, aber ohne moderne UX/JS-Verbesserungen.
