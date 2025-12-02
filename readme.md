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
- `DB/schema.sql` – Referenzschema für sämtliche Tabellen und Indizes.【F:DB/schema.sql†L3-L170】
- `SCRIPTS/` – CLI-Tools und gemeinsame Scan-Logik (`scan_core.php`).【F:SCRIPTS/scan_core.php†L4-L118】【F:SCRIPTS/scan_path_cli.php†L4-L75】
- `WWW/` – Webkomponenten (Dashboard, Thumbnails).【F:WWW/index.php†L1-L164】
- `TOOLS/` – optionaler Ablageort für ffmpeg/exiftool (nicht im Repo enthalten).【F:CONFIG/config.php†L30-L34】

## Datenbankschema (bestätigtes REFERENZSCHEMA_V1)
Alle Tabellen sind in `DB/schema.sql` definiert und entsprechen dem aktuellen Live-Schema:
- `media`: Pfad, Typ (image/video), Quelle, Maße, Videometadaten, Hash, Zeitstempel, Rating/NSFW-Flags, Parent-Verknüpfung und Status; Indizes auf Hash, Quelle, Rating, Status und Importzeit.【F:DB/schema.sql†L3-L37】
- `tags` & `media_tags`: Schlagwortverwaltung mit Lock-Flag und Konfidenz; Join-Tabelle mit PK (media_id, tag_id) plus Indizes auf beide Seiten.【F:DB/schema.sql†L41-L61】
- `scan_results`: Historie pro Scannerlauf inkl. NSFW-Score, Flags und Roh-JSON; Indizes auf media_id und scanner.【F:DB/schema.sql†L65-L81】
- `prompts`: Prompt-/Parameter-Archiv pro Medium (positive/negative Prompts, Modell, Sampler, CFG, Seed, Auflösung, Scheduler, JSON-Felder).【F:DB/schema.sql†L85-L107】
- `jobs`: FORGE-Aufträge inkl. Status, Zeitstempel, Request/Response-JSON und Fehlertext; Indizes auf Status und media_id.【F:DB/schema.sql†L111-L131】
- `collections` & `collection_media`: Virtuelle Ordner mit Many-to-Many-Beziehung; PK (collection_id, media_id) plus Indizes auf beide Spalten.【F:DB/schema.sql†L135-L154】
- `import_log`: Import-Historie mit Status und Zeitstempel, indiziert nach Status/created_at.【F:DB/schema.sql†L158-L170】
- `schema_migrations`: Versionierungstabelle für manuelle Migrationen mit `version`, `applied_at` und optionaler Beschreibung.【F:DB/schema.sql†L172-L178】

## Schema-Migrationen
- Neue Migrationen werden als Dateien `NNN_name.php` im Ordner `SCRIPTS/migrations/` abgelegt; der Dateiname (ohne `.php`) muss exakt dem `version`-Eintrag entsprechen und ein Array mit `version`, `description` und einer ausführbaren `run`-Funktion zurückgeben.【F:SCRIPTS/migrations/001_initial_schema.php†L1-L29】
- Ausführung erfolgt manuell über `php SCRIPTS/migrate.php`; das Skript legt bei Bedarf `schema_migrations` an, sortiert alle Dateien, führt nur fehlende Versionen aus und trägt sie nach Erfolg in die Tabelle ein.【F:SCRIPTS/migrate.php†L1-L113】【F:SCRIPTS/migrate.php†L141-L173】
- Die Baseline `001_initial_schema` markiert das bestätigte REFERENZSCHEMA_V1 und fügt lediglich einen Eintrag in `schema_migrations` hinzu, falls er noch fehlt.【F:SCRIPTS/migrations/001_initial_schema.php†L8-L29】
- Automatische Migrationen in Web- oder CLI-Scan-Skripten sind nicht vorgesehen; Änderungen müssen immer bewusst über den Runner gestartet werden.【F:SCRIPTS/migrate.php†L1-L6】

## Arbeitsabläufe
- **Erstimport & Scan**: `scan_path_cli.php` lädt Konfiguration, verbindet mit der DB und ruft `sv_run_scan_path` auf, um einen angegebenen Ordner rekursiv zu verarbeiten.【F:SCRIPTS/scan_path_cli.php†L12-L71】 Die zentrale Logik (`scan_core.php`) erkennt Dateityp, berechnet Hash/Metadaten, ruft den konfigurierten Scanner via HTTP auf, verschiebt Dateien in SFW/NSFW-Zielpfade und schreibt Datensätze in `media`, `scan_results`, `tags/media_tags` sowie `import_log`.【F:SCRIPTS/scan_core.php†L53-L228】【F:SCRIPTS/scan_core.php†L243-L336】
- **Rescan bestehender Medien**: `rescan_cli.php` nutzt `sv_run_rescan_unscanned`, um bereits importierte, aber ungescannte Medien erneut durch den Scanner zu schicken und Status/Ratings zu aktualisieren.【F:SCRIPTS/rescan_cli.php†L4-L67】【F:SCRIPTS/scan_core.php†L338-L452】
- **Filesystem-Sync**: `filesync_cli.php` führt `sv_run_filesync` aus, um die Existenz aller `media.path`-Einträge zu prüfen und den Status auf `active`/`missing` zu setzen.【F:SCRIPTS/filesync_cli.php†L4-L56】【F:SCRIPTS/scan_core.php†L454-L534】

## Weboberfläche
Das Dashboard (`WWW/index.php`) stellt eine einfache Übersicht bereit: PDO-Verbindung über `CONFIG/config.php`, Ausgabe der vorhandenen DB-Tabellen (SQLite) sowie Formulare, um Scan-, Rescan- und Filesync-CLI-Skripte im Hintergrund zu starten und Log-Dateien abzulegen.【F:WWW/index.php†L6-L164】

## Sicherheit und Betrieb
- API-Tokens/Whitelists konfigurieren, bevor Scanner-Aufrufe produktiv genutzt werden.【F:CONFIG/config.php†L36-L51】
- Dateipfade und Log-Verzeichnisse in `CONFIG/config.php` an die Zielumgebung anpassen; Standardwerte zeigen auf Windows-Laufwerke.【F:CONFIG/config.php†L17-L34】
- ffmpeg/exiftool sind optional und müssen separat installiert oder in `TOOLS/` bereitgestellt werden.【F:dependencies.txt†L20-L22】【F:CONFIG/config.php†L30-L34】

