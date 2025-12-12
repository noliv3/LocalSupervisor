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
- **Scan**: `scan_core` identifiziert Typ (Bild/Video), berechnet Hash, extrahiert Basis-Metadaten, ruft den konfigurierten Scanner via HTTP, verschiebt Dateien in die gültigen SFW/NSFW-Zielpfade und schreibt `media`, `scan_results`, `tags/media_tags`, `import_log` sowie `media_meta`/`prompts`. Bekannte System-/Trash-Ordner (`$RECYCLE.BIN`, `System Volume Information`, `.Trash` u. a.) werden rekursiv übersprungen; Berechtigungsfehler erhöhen nur den Error-Zähler und brechen den Lauf nicht ab.
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

## Asynchrone Scans

- Web-Trigger für Scans legen ausschließlich Jobs vom Typ `scan_path` in der Queue an und starten automatisch einen dedizierten Worker im Hintergrund.
- Der Worker läuft rein im CLI-Kontext (`SCRIPTS/scan_worker_cli.php`) und zieht queued/running-Scans ohne Web-Timeouts ab, Status landet in `jobs.status/forge_response_json`.
- Beispiel: `php SCRIPTS/scan_worker_cli.php --path="/data/import" --limit=5` verarbeitet maximal fünf anstehende Scans für den angegebenen Wurzelpfad.

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

## Konsistenz-Tools
- **UI-Indikatoren**: `mediadb.php` und `media_view.php` zeigen Badges für Prompt-Vollständigkeit, Tags und Metadaten an. Filter `incomplete=` (prompt/tags/meta/any) erleichtern die Suche nach Lücken.
- **Mini-Konsistenzcheck**: Direkt in der Detailansicht werden pro Medium die Stati (Prompt vollständig, Tags, Metadaten) angezeigt.
- **Komfort-Rebuild**: Im Dashboard steht ein Button „Rebuild fehlender Prompts“, der nur Medien mit fehlenden Prompt-Kernfeldern (internes Limit 100) per Einzel-Rebuild anstößt und Audit-Log-Einträge schreibt.

## Integritätsanalyse und einfache Reparatur
- **Analyse (read-only)**: `SCRIPTS/operations.php` stellt Prüfungen bereit, die fehlende Hashes, fehlende Dateien (Status `active`), Prompts ohne Roh-Metadaten und Tag-Zuordnungen ohne Confidence erkennen. Ergebnisse werden strukturiert pro Medium/Typ zurückgegeben.
- **UI-Anzeigen**: `media_view.php` listet konkrete Probleme des Mediums (erste drei Zeilen, Rest aufklappbar). `mediadb.php` bietet einen Filter `?issues=1` und markiert betroffene Medien in der Grid-Ansicht. Das Dashboard (`index.php`) zeigt die Anzahl der problematischen Medien im Abschnitt „Integritätsstatus“.
- **Einfache Reparatur**: Über das Dashboard (Internal-Key/IP-Whitelist erforderlich) kann eine minimale Reparatur ausgelöst werden. Sie setzt nur den Status auf `missing`, wenn Dateien fehlen, entfernt `media_tags`-Einträge ohne Confidence und löscht komplett leere Prompt-Objekte. Alle Schritte laufen über `SCRIPTS/operations.php` und werden auditgeloggt.

## Prompt-Qualität (A/B/C)
- **Zentrale Bewertung**: `SCRIPTS/operations.php` stellt eine Heuristik bereit (`sv_analyze_prompt_quality`), die Prompts in A/B/C klassifiziert, Score/Issues liefert und Tag-basierte bzw. hybride Vorschläge generiert.
- **UI-Anzeigen**: `media_view.php` zeigt die Klasse, Score, Issues (Top 3) und optionale Vorschläge (Tag-basiert/Hybrid) direkt neben dem Prompt an.
- **Filter/Badges**: `mediadb.php` bietet einen Filter `prompt_quality=A|B|C` (Alias `critical` für C) und zeigt pro Medium ein PQ-Badge mit Score/Issues an.
- **Dashboard-Summary**: `index.php` summiert die Prompt-Klassen (Sample bis 2000 Medien) und verlinkt für kritische Prompts auf `mediadb.php?prompt_quality=C`.

## Forge-Regeneration
- **Async-Flow**:
  1. In der Detailansicht (`media_view.php`) „Regen über Forge“ klicken: Prompt-Heuristik (A/B/C + Tag-Fallback) läuft im Web-Request, Modell wird gegen Forge gelöst, anschließend wird nur ein Job (`jobs.type=forge_regen`) im Status `queued` angelegt.
  2. CLI-Worker ausführen (z. B. regelmäßig per Cron: `php SCRIPTS/forge_worker_cli.php --limit=1`): Der Worker lädt queued/running-Jobs, ruft Forge, legt ein Backup an, ersetzt die Datei, stößt Re-Scan/Prompt/Tag-Refresh an und schreibt Audit-/Job-Response.
  3. UI-Feedback: `media_view.php` blendet ein Forge-Job-Panel ein und pollt den Status per AJAX; das Dashboard (`index.php`) zeigt eine Übersicht offener/erfolgreicher/fehlerhafter Jobs. Keine Web-Requests warten auf Forge.
  4. In der Grid-Ansicht (`media.php`) gibt es einen Button „Forge Regen“ pro Medium. Der Klick legt einen Job an, stößt sofort einen dedizierten Worker (`php SCRIPTS/forge_worker_cli.php --limit=1 --media-id=<ID>`) an und zeigt Job-ID, Status und Worker-PID direkt im UI (Live-Polling, keine Wartezeit im Request).
- **Replace in place**: Der Worker ersetzt die Datei auf demselben Pfad (inkl. Hash/Größe/Auflösung-Update), legt Backups an und führt danach Re-Scan/Metadaten-/Prompt-Aktualisierung durch, damit Tags/Prompts/Meta zum neuen Bild passen.
- **Job-Verfolgung**: Die Job-Request/Response-Daten werden in `jobs.forge_request_json`/`jobs.forge_response_json` abgelegt; Statusübergänge (queued/running/done/error) bleiben auditierbar. Media-Details zeigen die letzten Jobs mit Status/Modell, das Dashboard fasst Zählungen zusammen.
- **Versionen (read-only)**: `media_view.php` zeigt eine Versionsliste pro Medium. Version 0 entspricht dem Import, weitere Versionen stammen aus `forge_regen`-Jobs (Status ok/error). Sichtbar sind Zeitstempel, Quelle, gewünschtes/benutztes Modell, Prompt-Kategorie/Fallback, Hash-Wechsel sowie Backuppfad (falls vorhanden); keine Restore-Funktion.

### Job-Center (Dashboard)
- `WWW/index.php` bietet einen Job-Center-Block mit Filtern für `job_type`, `status`, `media_id` und Zeitfenster (24h/7d/30d).
- Die Tabelle listet ID, Typ, Media-Link, Status, Zeitstempel und eine Kurzinfo pro Job.
- Steuerung: „Requeue“ für `error`/`done`/`canceled`, „Cancel“ für `queued`/`running`; Schreibaktionen erfordern Internal-Key/IP-Whitelist und rufen ausschließlich `SCRIPTS/operations.php`.
- Verarbeitung der Jobs bleibt beim CLI-Worker (`forge_worker_cli.php`), die Weboberfläche erzeugt oder manipuliert keine Forge-Aufrufe direkt.

## Hashbasierte Library, Dupes und Rename-Backfill
- **Dateiablage**: Neu importierte Medien landen hashbasiert unter `<hh>/<hash>.<ext>` (erste zwei Hex-Zeichen als Ordner). Pfade werden zentral über `sv_resolve_library_path` erzeugt.
- **Originalreferenzen**: Der ursprüngliche Importpfad/Dateiname wird als `media_meta` (`source=import`, Keys `original_path`/`original_name`) gesichert.
- **Nachpflege**: Abweichende Altbestände können im Dashboard als `library_rename`-Jobs eingeplant und via `php SCRIPTS/library_rename_worker_cli.php --limit=N` abgearbeitet werden. Der Worker verschiebt Dateien in das neue Schema, aktualisiert `media.path` und protokolliert `rename_at`.
- **Dupes**: Strikte Duplikate basieren auf identischem Hash. `mediadb.php` unterstützt die Filter `dupes=1` und `dupe_hash=...` und zeigt Dupe-Badges je Hash-Gruppe.

## Bekannte Einschränkungen / Offene Baustellen
- Prompt-Historie fehlt; Raw-Blöcke werden zwar gespeichert, aber Historisierung/Versionierung der Prompts ist nicht vorhanden.
- Automatische Regeneration aus bestehenden `media_meta`-Snapshots existiert nicht; Rebuild liest immer von der Quelldatei.
- Delete-/Qualitätsmechanik (automatisches Löschen/Retagging) ist nicht implementiert; Status-Flag `missing` ersetzt Löschungen.
- UI-Modernisierung steht aus: Dashboard/Listen/Detail sind funktional, aber ohne moderne UX/JS-Verbesserungen.
