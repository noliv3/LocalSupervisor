# SuperVisOr – gehärtete Übersicht

## Projektüberblick
SuperVisOr ist ein PHP-basiertes Werkzeug für das lokale Management großer Bild- und Video-Sammlungen. Es kombiniert Scanner-gestützten Import, Prompt-Pipeline, Tagging und eine Weboberfläche zur Anzeige und Steuerung wiederkehrender Abläufe. Ziel ist eine konsistente Datenbasis aus Dateien, Prompts und Metadaten ohne Abhängigkeit von Cloud-Diensten.

## Dokumentationsstruktur
- **README.md** (dieses Dokument): Projektbeschreibung, Architektur, Setup und Betriebsabläufe. Ehemalige Inhalte aus `docs/SETUP.md` und `docs/ASSETS.md` sind hier integriert; es existieren keine separaten Dateien mehr.
- **AGENTS.MD**: Verbindliche Anforderungen, Prozesse, Sicherheits- und Architekturregeln.
- **LOG.MD**: Änderungs- und Revisionsprotokoll.

> Hinweis: Aufgrund der Vorgabe „maximal 3 MD-Dateien“ sind die geforderten Outputs **REPORT_SYSTEM_MAP**, **CHECKLIST_SYSTEM**, **CHECKLIST_SMOKE** und **CHANGELOG_NOTES** **in dieses README bzw. LOG.MD integriert** (keine zusätzlichen Markdown-Dateien).

## REPORT_SYSTEM_MAP (inline)
**Entry-Points (Repository-Scan, passiv):**
- **WWW/\*.php**: HTTP-Routen (Dashboard, Gallery, Detail, Stream/Thumb, Health).
- **SCRIPTS/\*.php**: CLI-/Kernel-Flows (Scan/Rescan, Jobs, DB, Backups, Konsistenz).
- **start.ps1**: Supervisor/Start/Update-Flow.

**Module → Dateien → Hauptfunktionen → Inputs/Outputs (Dateien/DB/Routen):**
1. **Umgebung & Pfade**
   - Dateien: `start.ps1`, `SCRIPTS/paths.php`, `SCRIPTS/common.php`
   - Hauptfunktionen: Basisverzeichnis (`SV_BASE`), Pfadnormalisierung, Media-Roots/Allowlist
   - Inputs: ENV (`SV_BASE`), `CONFIG/config.php`/`config.example.php`, `paths.*`
   - Outputs: Normalisierte Pfade, erlaubte Roots, Verzeichnislayout (`LOGS/`, `BACKUPS/`, `TMP/`, `PREVIEWS/`)
2. **Repo/Git-Update-Flow**
   - Dateien: `start.ps1`, `LOGS/git_status.json`, `LOGS/git_update.last.json`
   - Hauptfunktionen: `git fetch`, ahead/behind, FF-only Update, Lock-Serialisierung
   - Inputs: Git-Remote, Working Tree, `LOGS/start.lock`, `LOGS/update.lock`
   - Outputs: JSON-Statusdateien, Update-Exit-Status im Log
3. **PHP Runtime/Ini/Extensions**
   - Dateien: `start.ps1`, `LOGS/php_server.out.log`, `LOGS/php_server.err.log`
   - Hauptfunktionen: PHP-CLI/Server-Start, Ini/Extensions-Load
   - Inputs: `php.ini`, Pfad zu PHP (TOOLS/php oder PATH)
   - Outputs: Server-PID (`LOGS/php_server.pid`), Error/Out-Logs
4. **Webserver/HTTP-Routen**
   - Dateien: `WWW/index.php`, `WWW/mediadb.php`, `WWW/media_view.php`, `WWW/media.php`, `WWW/media_stream.php`, `WWW/thumb.php`, `WWW/health.php`
   - Hauptfunktionen: Dashboard/Health, Galerie/Detail, Streaming/Thumbs
   - Inputs: HTTP-Requests, Internal-Key/IP-Whitelist
   - Outputs: HTML/JSON, HTTP-Statuscodes (200/403/5xx)
5. **Datenbank: DSN, Schema, Migrationen**
   - Dateien: `SCRIPTS/db_status.php`, `SCRIPTS/migrate.php`, `SCRIPTS/db_helpers.php`, `DB/schema.sql`
   - Hauptfunktionen: DB-Status, Migrationslauf, Schema-Check
   - Inputs: DSN (Config), DB-Datei/Server
   - Outputs: `schema_migrations`, DB-Status-Report (Exit-Code ≠ 0 bei Problemen)
6. **Backups/Restore/Rotation**
   - Dateien: `SCRIPTS/db_backup.php`, `BACKUPS/`, `LOGS/`
   - Hauptfunktionen: DB-Backup, Rotation, `.meta.json`-Beiblatt
   - Inputs: DSN, Backup-Keep-Wert
   - Outputs: `BACKUPS/*.sqlite(.gz)` + `.meta.json`
7. **Medien: Import, Pfade, Thumbs, Stream/Range**
   - Dateien: `SCRIPTS/scan_core.php`, `SCRIPTS/thumb_core.php`, `WWW/thumb.php`, `WWW/media_stream.php`, `SCRIPTS/paths.php`
   - Hauptfunktionen: Import/Hash-Pfad, Thumb-Generierung, HTTP-Range-Stream
   - Inputs: Media-Dateien, `paths.*`, ffmpeg (optional)
   - Outputs: `media`/`media_meta`/`scan_results`/`tags`, Thumb/Stream-Responses
8. **Scanner: check, batch, rescan, Persistenz, Tag-Locking**
   - Dateien: `SCRIPTS/scan_core.php`, `SCRIPTS/scan_worker_cli.php`, `SCRIPTS/rescan_cli.php`, `SCRIPTS/operations.php`
   - Hauptfunktionen: Scanner-HTTP, Tagging, Rescan-Persistenz, Locked-Tags
   - Inputs: `scanner.*`-Config, Media-Dateien
   - Outputs: `scan_results`, `media_tags` (locked geschützt), Job-Status
9. **Jobs/Queue/Operations**
   - Dateien: `SCRIPTS/operations.php`, `SCRIPTS/forge_worker_cli.php`, `SCRIPTS/scan_worker_cli.php`
   - Hauptfunktionen: Job-Erzeugung, Status/Retry, Worker-Dispatch
   - Inputs: Job-Requests (Web/CLI), `jobs`-Tabelle
   - Outputs: `jobs`-Status, Worker-Logs, Error-Metadaten
10. **Logging/Observability**
   - Dateien: `SCRIPTS/logging.php`, `LOGS/start.log`, `LOGS/php_server.*.log`, `LOGS/scanner_ingest.jsonl`
   - Hauptfunktionen: Log-Write/Rotation, Start-/Server-/Scanner-Logs
   - Inputs: Runtime-Events, Errors
   - Outputs: JSON/Plaintext-Logs, strukturierte Einträge
11. **Security: Bind-Adresse, Token-Key Pflicht, Dateischutz**
   - Dateien: `SCRIPTS/security.php`, `WWW/media_stream.php`, `WWW/thumb.php`, `WWW/index.php`
   - Hauptfunktionen: Internal-Key/IP-Whitelist, Pfadvalidierung, Public Read-only
   - Inputs: `internal_key`, Whitelist, Request-IP
   - Outputs: HTTP 403/200, Audit-Log-Einträge
12. **Recovery: Rollback, Restore, Lock-Reset, Safe-Stop**
   - Dateien: `SCRIPTS/consistency_check.php`, `SCRIPTS/db_backup.php`, `SCRIPTS/migrate.php`, `start.ps1`
   - Hauptfunktionen: Konsistenz-Check, Restore-Flow, Lock-Handling
   - Inputs: DB-Backup, Lockfiles, Repair-Flags
   - Outputs: Reports, Exit-Codes, Wiederherstellungsläufe

**Artefakte/Logs (passiv zu sammeln, Pfade aus Config/Defaults):**
- `LOGS/start.log`
- `LOGS/php_server.out.log`, `LOGS/php_server.err.log`, `LOGS/php_server.pid`
- `LOGS/git_status.json`, `LOGS/git_update.last.json`
- `LOGS/scanner_ingest.jsonl` (falls Scanner aktiv)
- Weitere Job-/Worker-Logs in `LOGS/` (z. B. Forge-Worker-Logs)

## CHECKLIST_SYSTEM (inline, abhakbar)
> Struktur: **PASSIV (lesen)** → **AKTIV (read-only)** → **DESTRUKTIV (opt-in)**. Keine Feature-Erweiterungen; nur Diagnose und minimale Fixes bei objektivem Defekt.

### 1) Umgebung & Pfade
- **Sollzustand:** Basisverzeichnis korrekt; Pfade zeigen auf gültige Roots; LOGS/BACKUPS/TMP existieren oder können angelegt werden.
- **Nachweis (PASSIV):** Config lesen (`paths.*`, `SV_BASE`), Pfadnormalisierung nachvollziehbar.
- **Nachweis (AKTIV):** Read-only `realpath`/`stat` auf konfigurierte Roots.
- **Fehlerbilder:** Pfade zeigen ins Nirgendwo; Pfad-Traversal-Fehler; fehlende Roots.
- **Stop-Kriterium:** Pfad zeigt außerhalb erlaubter Media-Roots.

### 2) Repo/Git-Update-Flow
- **Sollzustand:** Working Tree sauber; Fetch-Status klar; Update-Locks verhindern Parallel-Updates.
- **Nachweis (PASSIV):** `LOGS/git_status.json`/`LOGS/git_update.last.json` lesen.
- **Nachweis (AKTIV):** `git status --porcelain`, `git rev-parse` (read-only).
- **Fehlerbilder:** Dirty Tree + Auto-Update; Lockfiles ohne Fortschritt.
- **Stop-Kriterium:** Update startet trotz Dirty Tree/Conflict.

### 3) PHP Runtime/Ini/Extensions
- **Sollzustand:** PHP lädt Ini; benötigte Extensions geladen; keine doppelten Loads.
- **Nachweis (PASSIV):** `LOGS/php_server.err.log` prüfen.
- **Nachweis (AKTIV):** `php -i` (read-only) zeigt Loaded Configuration File.
- **Fehlerbilder:** „already loaded“, Fatal Errors beim Start.
- **Stop-Kriterium:** wiederkehrende Fatal Errors im PHP-Serverlog.

### 4) Webserver/HTTP-Routen
- **Sollzustand:** `WWW/health.php` liefert 200; zentrale Routen reagieren.
- **Nachweis (PASSIV):** Server-Logs/Health-Status lesen.
- **Nachweis (AKTIV):** `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/health.php`.
- **Fehlerbilder:** 5xx auf Health; Timeouts.
- **Stop-Kriterium:** wiederholte 5xx/Timeouts bei Health.

### 5) Datenbank: DSN, Schema, Migrationen
- **Sollzustand:** DSN korrekt; Schema vollständig; Migrationen up-to-date.
- **Nachweis (PASSIV):** `DB/schema.sql`, `schema_migrations` Erwartung.
- **Nachweis (AKTIV):** `php SCRIPTS/db_status.php` (Exit-Code 0).
- **Fehlerbilder:** fehlende Tabellen/Spalten; offene Migrationen.
- **Stop-Kriterium:** Migrationen inkonsistent oder DB nicht erreichbar.

### 6) Backups/Restore/Rotation
- **Sollzustand:** Backups werden versioniert; Rotation hält `BackupKeep`.
- **Nachweis (PASSIV):** Backup-Ordner/Meta-Dateien prüfen.
- **Nachweis (AKTIV):** Read-only Listing der Backup-Dateien/Metas.
- **Fehlerbilder:** Überschreiben statt Versionieren; fehlende `.meta.json`.
- **Stop-Kriterium:** Backup überschreibt letzte Sicherung.

### 7) Medien: Import, Pfade, Thumbs, Stream/Range
- **Sollzustand:** Medienroots existieren; Thumbs/Streams liefern Content.
- **Nachweis (PASSIV):** Pfadkonfig lesen; Logs prüfen.
- **Nachweis (AKTIV):** `curl -I` auf `thumb.php?id=...` (read-only) und Range-Header-Check für `media_stream.php?id=...`.
- **Fehlerbilder:** Thumb/Stream 5xx, Range ignoriert.
- **Stop-Kriterium:** Stream/Thumb löst PHP-Fatal aus.

### 8) Scanner: check, batch, rescan, Persistenz, Tag-Locking
- **Sollzustand:** Scanner erreichbar; Rescan aktualisiert run_at; Locked-Tags bleiben.
- **Nachweis (PASSIV):** Scanner-Config + `LOGS/scanner_ingest.jsonl`.
- **Nachweis (AKTIV):** Read-only HTTP-Healthcheck; `db_status`/Query auf letzte `scan_results`.
- **Fehlerbilder:** Unknown response; fehlende Persistenz; Locked-Tags gelöscht.
- **Stop-Kriterium:** Scanner erreicht, aber Parser bricht ohne Audit ab.

### 9) Jobs/Queue/Operations
- **Sollzustand:** Jobs zeigen Status/Timestamps; Retry/Errors nachvollziehbar.
- **Nachweis (PASSIV):** `jobs`-Tabelle und Job-Logs prüfen.
- **Nachweis (AKTIV):** Read-only Query auf `jobs`-Status (running/queued/error).
- **Fehlerbilder:** Jobs bleiben dauerhaft `running`; fehlende Error-Metadaten.
- **Stop-Kriterium:** Stuck-Jobs ohne Transition/Repair.

### 10) Logging/Observability
- **Sollzustand:** Logs lesbar, keine Floods, Encoding ok.
- **Nachweis (PASSIV):** `LOGS/start.log`, `LOGS/php_server.err.log`.
- **Nachweis (AKTIV):** `tail -n` (read-only) + Encoding-Stichprobe.
- **Fehlerbilder:** Log-Flooding; Encoding-Zerfall.
- **Stop-Kriterium:** Logs unlesbar oder überschreiben sich.

### 11) Security: Bind-Adresse, Token-Key Pflicht, Dateischutz
- **Sollzustand:** Bind auf 127.0.0.1; Internal-Key Pflicht für Schreibpfade.
- **Nachweis (PASSIV):** Config/Logs prüfen; Allowed Roots.
- **Nachweis (AKTIV):** Requests ohne Key erhalten 403 (read-only).
- **Fehlerbilder:** Öffentliche Logs/Backups; fehlende Key-Prüfung.
- **Stop-Kriterium:** Sensible Daten öffentlich erreichbar.

### 12) Recovery: Rollback, Restore, Lock-Reset, Safe-Stop
- **Sollzustand:** Restore-Prozess dokumentiert; Lock-Reset löst Stale Locks.
- **Nachweis (PASSIV):** Backup- und Restore-Anweisungen; Lockfiles.
- **Nachweis (AKTIV):** `php SCRIPTS/consistency_check.php` ohne Repair (Report/Exit-Code).
- **Fehlerbilder:** Restore verhindert Start; Locks bleiben hängen.
- **Stop-Kriterium:** Restore führt zu Datenverlust-Risiko.

### DESTRUKTIV (opt-in, klar abgetrennt)
- **Nur wenn explizit freigegeben:** Stop/Restart, Restore von Backup, Lock-Reset, Repair/Migrationen mit Write.
- **Voraussetzung:** Vollständiges Backup liegt vor; Operator bestätigt.

## CHECKLIST_SMOKE (inline, copy/paste, read-only)
> **Nur nicht-destruktive Checks.** Erwartung ist deterministisch beschrieben. Keine Tests wurden in dieser Änderung ausgeführt.

### Umgebung & Pfade
- `[ ]` `pwd` → zeigt Projektroot (SV_BASE).
- `[ ]` `ls LOGS BACKUPS` → Verzeichnisse existieren (oder Hinweis, dass sie im Betrieb angelegt werden).

### Repo/Git-Update-Flow
- `[ ]` `git status --porcelain` → **keine** Ausgabe.
- `[ ]` `cat LOGS/git_status.json` → enthält `fetch_ok` und `ahead/behind`.

### PHP Runtime/Ini/Extensions
- `[ ]` `php -v` → Version wird ausgegeben.
- `[ ]` `php -i | rg "Loaded Configuration File"` → Zeile vorhanden.
- `[ ]` `rg "already loaded" LOGS/php_server.err.log` → **keine** Treffer.

### Webserver/HTTP-Routen
- `[ ]` `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/health.php` → `200`.
- `[ ]` `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/` → `200/302`.

### Datenbank: DSN, Schema, Migrationen
- `[ ]` `php SCRIPTS/db_status.php` → Exit-Code `0`.

### Backups/Restore/Rotation
- `[ ]` `ls BACKUPS` → mindestens ein Backup-File (bzw. leer bei frischem System).
- `[ ]` `rg "\\.meta\\.json" BACKUPS` → Meta-Dateien vorhanden (wenn Backups existieren).

### Medien: Import, Pfade, Thumbs, Stream/Range
- `[ ]` `curl -I "http://127.0.0.1:8080/thumb.php?id=<media_id>"` → `200` und `Content-Type: image/*`.
- `[ ]` `curl -I -H "Range: bytes=0-1" "http://127.0.0.1:8080/media_stream.php?id=<media_id>"` → `206`.

### Scanner
- `[ ]` `rg "scanner.base_url" CONFIG/config.php CONFIG/config.example.php` → URL gesetzt.
- `[ ]` `tail -n 5 LOGS/scanner_ingest.jsonl` → letzte Events sichtbar (wenn Scanner genutzt).

### Jobs/Queue/Operations
- `[ ]` `php SCRIPTS/db_inspect.php` → letzte Jobs/Scan-Ergebnisse sichtbar.
- `[ ]` `rg "error" LOGS/forge_worker_runtime.log` → keine neuen Fehler (falls Forge genutzt).

### Logging/Observability
- `[ ]` `tail -n 50 LOGS/start.log` → lesbar, kein Flooding.
- `[ ]` `tail -n 50 LOGS/php_server.err.log` → keine wiederholten Fatal Errors.

### Security
- `[ ]` `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8080/media_view.php` → `200` (read-only).
- `[ ]` `curl -s -o /dev/null -w "%{http_code}" -X POST http://127.0.0.1:8080/media_view.php` → `403`.

### Recovery
- `[ ]` `ls LOGS/*.lock` → Lockfiles sichtbar; keine endlosen Updates.
- `[ ]` `php SCRIPTS/consistency_check.php` → Report/Exit-Code `0` (ohne Repair).

### DESTRUKTIV (opt-in, separat)
- `[ ]` `php SCRIPTS/consistency_check.php --repair` → **nur** mit Operator-Freigabe.
- `[ ]` Restore aus Backup durch Service-Stop + Copy → **nur** mit Freigabe.

## Prüflisten-Ziel (Betriebschecks in Modulen)
Die Prüfliste zerlegt den Betrieb in Module mit **Sollzustand**, **Nachweis** und **Stop-Kriterium**. Die Reihenfolge ist verbindlich: **passiv → aktiv → destruktiv**. Die Nachweise sind als Read-Only-Checks definiert und dürfen die laufenden Prozesse nicht verändern. Stop-Kriterien sind strikt einzuhalten, sobald ein Risiko für Datenverlust oder Prozess-Stürme erkennbar ist.

### 0. Regeln (passiv → aktiv → destruktiv)
- **Sollzustand**: Prüfungen erfolgen stufenweise (passiv/aktiv/destruktiv) ohne Seiteneffekte.
- **Nachweis**:
  - Passiv: nur lesen, keine Prozesse ändern.
  - Aktiv: Requests, Read-Only-Checks, keine Löschungen.
  - Destruktiv: Stop, Kill, Restore, Reset.
  - Stop-Kriterium: Datenverlust-Risiko oder Prozess-Sturm.
- **Stop-Kriterium**: Datenverlust-Risiko oder Prozess-Sturm.

### 1. Basisumgebung
- **Sollzustand**: Basisverzeichnis eindeutig; Schreibrechte für LOGS, BACKUPS, DB-Datei, Medienordner.
- **Nachweis**: `$env:SV_BASE` oder Scriptroot zeigt auf Projekt; Dateien lassen sich erstellen und wieder löschen.
- **Stop-Kriterium**: Datenverlust-Risiko oder Prozess-Sturm.

### 2. Git-Stand und Update-Kanal
- **Sollzustand**: Working Tree sauber; Fetch funktioniert; Ahead/Behind plausibel; Update-Locks blockieren Parallel-Updates.
- **Nachweis**:
  - `git status --porcelain` ist leer.
  - `LOGS/git_status.json` enthält `fetch_ok: true`.
  - `git_status` enthält `ahead`/`behind` als Zahlen oder null.
  - `LOGS/update.lock` blockiert zweite Update-Instanz.
- **Stop-Kriterium**: Dirty Tree + automatischer Pull.

### 3. Supervisor-Locks und Ein-Instanz-Garantie
- **Sollzustand**: `start.lock` verhindert Doppelstart; Lock enthält PID des Supervisors.
- **Nachweis**:
  - Zweiter Start scheitert wegen Lock.
  - JSON-PID entspricht laufendem PowerShell-Prozess.
- **Stop-Kriterium**: Lock wird permanent neu geschrieben ohne Fortschritt.

### 4. PHP-Server-Prozess und Watchdog
- **Sollzustand**: Genau eine Instanz bindet 127.0.0.1:8080; `LOGS/php_server.pid` zeigt auf existierenden Prozess; Watchdog startet nicht permanent neu.
- **Nachweis**:
  - `netstat -ano | findstr :8080` zeigt eine LISTEN-PID.
  - `Get-Process -Id (Get-Content LOGS\php_server.pid)` funktioniert.
  - `start.log` zeigt keine 5-Sekunden-Restart-Schleife.
  - Keine wiederholte Zeile „PID … ist kein gültiger PHP-Server“.
- **Stop-Kriterium**: Wiederholte „Neustart durch Watchdog“ Einträge im 5-Sekunden-Takt.

### 5. PHP-Runtime und ini/extensions
- **Sollzustand**: `php.ini` wird geladen; keine doppelt geladenen Extensions.
- **Nachweis**:
  - `php -i` zeigt „Loaded Configuration File“.
  - `php_server.err.log` enthält keine „already loaded“-Warnungen.
- **Stop-Kriterium**: Fatal Errors im Errorlog.

### 6. HTTP-Endpunkte
- **Sollzustand**: Health und Root liefern 200; `/favicon.ico` antwortet schnell (200/404).
- **Nachweis**:
  - `GET /health.php` ergibt 200.
  - `curl http://127.0.0.1:8080/` beendet sofort.
  - Browser-Netzwerklog zeigt keinen Hänger bei `/favicon.ico`.
- **Stop-Kriterium**: Requests hängen unbegrenzt.

### 7. DB: Init, Schema, Migration
- **Sollzustand**: Init und Migration laufen stabil; DB-Datei existiert am DSN-Pfad.
- **Nachweis**:
  - Startlog enthält keinen „DB-Init fehlgeschlagen“.
  - DSN beginnt mit `sqlite:` und Datei existiert.
  - `SCRIPTS/migrate.php` Exit 0, keine Wiederholungs-Änderungen bei erneutem Lauf.
- **Stop-Kriterium**: Migration schlägt fehl und Rollback startet nicht.

### 8. Backups und Rotation
- **Sollzustand**: Backup erzeugt SQLite-Datei mit Timestamp; Rotation entfernt nur alte Backups.
- **Nachweis**:
  - `BACKUPS/supervisor_*.sqlite` wird erstellt.
  - Anzahl Backups entspricht `BackupKeep`.
- **Stop-Kriterium**: Backup wird überschrieben statt versioniert.

### 9. Medienpfade, Thumbs, Stream
- **Sollzustand**: Medienordner existieren; Thumb liefert Bilddaten; Stream liefert Partial Content.
- **Nachweis**:
  - Konfigpfade zeigen auf vorhandene Ordner.
  - `WWW/thumb.php` antwortet mit Bild-Content-Type.
  - `WWW/media_stream.php` akzeptiert `Range`.
- **Stop-Kriterium**: Thumbs/Stream lösen PHP-Fatal aus.

### 10. Scanner-Subsystem
- **Sollzustand**: Scanner-API erreichbar; `/check` liefert Ergebnis; `/batch` liefert Ergebnis/sauberen Fehler; Rescan aktualisiert `run_at` und Tags.
- **Nachweis**:
  - Health/Ping liefert 200/JSON ohne Timeout.
  - `/check` liefert Objekt mit `nsfw`/`tags`.
  - `/batch` liefert Ergebnis oder klaren Fehler, kein „unknown response“ ohne Kontext.
  - UI/DB zeigt neuen Scanzeitpunkt und aktualisierte unlocked Tags.
- **Stop-Kriterium**: Video-Scan benötigt FFmpeg-Fallback, aber FFmpeg fehlt und es gibt keinen sauberen Abbruchpfad.

### 11. Jobs/Operations-Flow
- **Sollzustand**: Jobzustände sichtbar; Fehlerzustände enthalten Debug-Metadaten.
- **Nachweis**:
  - Status `running/done/error` und Timestamps sichtbar.
  - Fehler-JSON enthält `http_status`, `response_snippet`, `error_saved`.
- **Stop-Kriterium**: Jobs bleiben dauerhaft „running“.

### 12. Logging und Encoding
- **Sollzustand**: Logs wachsen kontrolliert und bleiben lesbar; keine Encoding-Probleme.
- **Nachweis**:
  - `start.log` bleibt lesbar, kein Flooding.
  - `php_server.err.log` enthält nur relevante Fehler.
  - Keine Umlaut-Zerlegung (z. B. `gÃ¼ltig`) im Startlog.
- **Stop-Kriterium**: Logs unbrauchbar durch Encoding oder Flooding.

### 13. Security-Grundannahmen
- **Sollzustand**: Server bindet nur 127.0.0.1; interne APIs benötigen Token/API-Key; sensible Dateien nicht web-exponiert.
- **Nachweis**:
  - Startmeldung zeigt `http://127.0.0.1:8080`.
  - Requests ohne Key scheitern erwartbar.
  - `.env`, `.git`, `BACKUPS`, `LOGS` nicht direkt abrufbar.
- **Stop-Kriterium**: Webroot enthält Backups oder Logs öffentlich.

### 14. Recovery und Reset
- **Sollzustand**: Safe-Stop ohne DB-Schaden; Restore vom Backup konsistent; Lock-Reset entfernt stale Locks.
- **Nachweis**:
  - Nächster Start funktioniert ohne Repair.
  - Restore startet und Migration passt.
  - Start blockiert nicht durch tote PID.
- **Stop-Kriterium**: Datenverlust-Risiko oder Prozess-Sturm.

## Offene Prüfpunkte (Status: offen)
Die folgenden Nachweise sind im Betrieb noch zu erbringen und wurden in dieser Dokumentation nur als Soll-Checkliste festgehalten:
- Module 0–14 vollständig offen; es liegen noch keine Nachweise für Passiv-/Aktiv-/Destruktiv-Stufen vor.
- Stop-Kriterien wurden nicht ausgelöst; es existiert kein Run-Log mit erfolgreichem Abschluss der Prüfsequenz.
- Hinweis: In dieser Änderung wurden keine Prüfungen ausgeführt (Anweisung: „Führe keine Tests durch“). Nachweise müssen später im Betrieb erhoben und hier ergänzt werden.

## Systemarchitektur
- **Kernel-Logik (SCRIPTS/)**: Zentrale Funktionen in `scan_core` (Dateierkennung, Hashing, Pfadvalidierung, Logging, DB-Writes), `prompt_parser` (EXIF/PNG/JSON-Kandidaten sammeln, priorisieren, normalisieren), `operations` (einheitliche Einstiegspunkte für Scan/Rescan/Filesync/Prompt-Rebuild/Konsistenz), `logging` (kanalisiertes Logging mit Rotation), `security` (Internal-Key + IP-Whitelist), `paths` (Pfadkonfiguration und Validierung).
- **Webschicht (WWW/)**: Dashboard `index.php` als Operator-Control-Center (Startpunkt, Health Snapshot, Job-Center, Operator-Aktionen, Ereignisverlauf), Hauptgalerie `mediadb.php`, Detail `media_view.php`, Streaming `media_stream.php`, Thumbnails `thumb.php`.
- **Persistenz (DB/)**: SQLite/MySQL-Schema aus `DB/schema.sql`, Migrationen in `SCRIPTS/migrations/`, Konfiguration in `CONFIG/config.php`.

## UI Map (Operator-Startpunkte)
- **Startpunkt (Galerie)**: `WWW/mediadb.php` ist die einzige produktive Galerie-UI (Card-Grid + List-Mode).
- **Operator-Dashboard**: `WWW/index.php` bündelt Startzugang, Health Snapshot, Job-Center, Operator-Aktionen (Scan/Rescan) und einen kurzen Ereignisverlauf.
- **Detailansicht**: `WWW/media_view.php` für Einzelmedium (Forge/Rescan/Tags/Curation/Prompt-Qualität).
- **Legacy-Pfad (nicht mehr verlinkt)**: `WWW/media.php` bleibt nur für Übergang/Alt-Workflows erreichbar und ist im UI als Legacy gekennzeichnet.
- **Nicht mehr nutzen**: Links/Navigation, die `media.php` als Standardzugang anbieten.

## Status-System (Curation vs. Prompt-Qualität)
- **Curation / Quality-Status (operativer Freigabezustand)**: Feld `media.quality_status` mit erlaubten Werten `unknown`, `ok`, `review`, `blocked` sowie optional `quality_score`/`quality_notes`. Änderungen werden in `media_lifecycle_events` protokolliert.
- **Prompt-Qualität (A/B/C)**: Abgeleitet aus Prompt/Parametern über `SCRIPTS/operations.php` (keine Persistenz). A/B/C beschreibt die Textqualität, nicht die Freigabe.
- **UI-Orte**:
  - Galerie (`WWW/mediadb.php`): getrennte Badges/Spalten „Curation“ und „Prompt“. Separate Filter: `curation=<unknown|ok|review|blocked>` und `prompt_quality=<A|B|C>`.
  - Detail (`WWW/media_view.php`): beide Werte klar getrennt angezeigt; Curation ist editierbar (Curation-Formular), Prompt-Qualität ist read-only. Letzte Änderung, sofern vorhanden, wird angezeigt.
- **Internal-Key**: Jede Änderung an Curation läuft ausschließlich über bestehende Internal-Key-geschützte POST-Flows (kein neuer Endpoint).

## Baseline (V1)
- Fallback-Konfiguration: Der Loader sucht zuerst nach `/mnt/data/config.php` (z. B. Docker-Volume), nutzt danach `CONFIG/config.php` und fällt mit Warnhinweis auf `CONFIG/config.example.php` zurück, damit Web/CLI auf frischem Checkout ohne Fatal Error starten.
- Schema-Sync: `DB/schema.sql` enthält das Flag `media_tags.locked`; die Migration `20260105_001_add_media_tags_locked.php` bleibt idempotent und füllt fehlende Spalten nach.
- Locked-Tag-Schutz: Cleanup/Repair entfernen keine gesperrten Tags; fehlende Dateien werden gemeldet, ohne manuelle Tagging-Daten zu löschen.
- VA/VIDAX-State: `va install` legt das State-Layout an und kopiert Beispielconfigs in `state/config`, falls dort noch nichts liegt.
- Optionale Tools: ffmpeg/ffprobe/exiftool sind optional; fehlende Tools führen zu klaren Hinweisen statt fatalen Fehlern (Video-Tests werden übersprungen, wenn ffmpeg fehlt).

## Dashboard Contract (Operator-Control-Center)
- **Zweck**: `WWW/index.php` ist der klare Operator-Startpunkt (kein Debug-View, keine Statistik-Wüsten).
- **Abschnitte**: Header/Start (Galerie + Anker), Health Snapshot, Job-Center, Operator-Aktionen, Ereignisverlauf.
- **Garantierte Daten**: Galerie-Link, DB/Job/Scan-Health (inkl. stuck jobs, letzter Fehler, letzter Scan), Job-Queues (running/queued/stuck/recent done|error) und letzte Audit-Events in Kurzform.
- **Aktionen**: Scan-Path-Batch, Rescan unscanned sowie Backfill „ohne Tags“ via Dashboard. Scan-Path-Batch akzeptiert mehrere Pfade (ein Pfad pro Zeile, Ordner oder Datei). Forge-Worker und Konsistenzcheck werden als Status/Quick-Link angezeigt, nicht als neue Web-Tools. Internal-Key/IP-Whitelist bleibt Pflicht für alle Web-Schreibaktionen.

## Update Center (Dashboard)
- `start.ps1` schreibt alle 3 Stunden einen Git-Status (`git fetch` + ahead/behind/dirty) nach `LOGS/git_status.json`.
- `start.ps1` nutzt standardmäßig das Skriptverzeichnis als Basis (optional `SV_BASE`) und verwendet `TOOLS/php` falls vorhanden, sonst `php` aus dem `PATH`.
- Der Dashboard-Button (nur Internal-Key/IP-Whitelist) startet einen einmaligen Update-Run über `start.ps1 -Action update_ff_restart`.
- Update-Run-Reihenfolge: FF-only Pull (nur wenn `behind>0`), `php SCRIPTS/db_backup.php`, Backup-Rotation (Default: 8 letzte Snapshots), `php SCRIPTS/migrate.php`, Dienst-Restart (mindestens PHP-Server).
- Dirty Working Tree oder nicht mögliches FF (Divergenz) blockiert den Update-Run; kein Restart, klare Fehlerzeile in `LOGS/git_update.last.json`.
- Merge ist nur via expliziter Action (`merge_restart`) erlaubt und ebenfalls nur mit clean tree; Konflikt → Abbruch ohne Restart.
- Start/Update sind durch Lockfiles serialisiert (`LOGS/start.lock`, `LOGS/update.lock`); PHP-Server-Logs landen in `LOGS/php_server.out.log`/`LOGS/php_server.err.log`.

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
- **Rescan**: Sendet vorhandene Medien erneut an den Scanner, aktualisiert Status/Ratings/NSFW und füllt fehlende Metadaten nach; Single-Media-Rescan läuft als Job (`rescan_media`) und nutzt dieselbe Queue wie der Scan-Worker. Jeder Lauf persistiert `scan_results.run_at` samt Scanner/Fehler im Scan-Result, ersetzt deduplizierte Tags ausschließlich für `locked=0` (Locks bleiben bestehen); Fehler landen im Jobstatus + Audit, erfolgreiche Läufe räumen `scan_stale` ab, die UI zeigt Run/Scanner/NSFW/Rating/Tags und den letzten Fehler.
- **Filesync**: Prüft die Existenz der `media.path`-Einträge und setzt Status `active`/`missing`; optional in Batches.
- **Prompt-Extraktion & -Normalisierung**: Kandidaten aus EXIF-Kommentaren, PNG-Text, Parameter-Strings und JSON-Blöcken werden gesammelt, gewichtet und in `prompts` strukturiert; Raw-Blöcke landen parallel in `media_meta`.
- **Prompt-Rebuild**: Liest aktive Medien mit fehlenden Kernfeldern erneut von der Quelldatei und wendet die Prompt-Pipeline an (keine Auswertung bestehender `media_meta`-Snapshots).
- **Tag-Pipeline**: Scanner liefert Tags/Confidence; Persistenz erfolgt in `tags`/`media_tags` mit Lock-Flag, um manuelle Korrekturen zu schützen.
- **Medienanzeige**: `mediadb.php` liefert die produktive Card-Grid-Ansicht (Default) mit optionalem List-Mode, Filtern und Badges; `media_view.php` zeigt Details inkl. Metadaten/Prompts, `media_stream.php`/`thumb.php` streamen geprüfte Pfade (inkl. Video-Range/Video-Thumbnails via ffmpeg). Forge-Preview/Backup/Output-Dateien werden ausschließlich serverseitig über `job_id` + `asset` (preview|backup|output) aufgelöst und mit den gleichen NSFW-Regeln wie das Zielmedium angezeigt. Die Detailansicht ist als zweispaltige Workbench mit großem Preview, Aktions-/Status-Panels und einklappbaren Prompt/Tags/Meta-Bereichen aufgebaut.
- **Detail-Rework**: Die Media-Detailseite bietet nun einen Version-Switch (Original/Forge-Versionen) mit expliziter Asset-Auswahl (Baseline vs. Job-Preview/Backup/Output), einen editierbaren NSFW-Schalter mit Pfad-Move in die passende Root, sowie einen geführten Recreate-Block (Strategie wählen → Parameter prüfen → Preview/Replace-Job starten). Tags können im selben Screen editiert oder gelockt werden; ein Rescan-Button reiht einen `rescan_media`-Job ein, pollt den Status (queued/running/done/error) und zeigt den letzten Scan (Zeit/Scanner/NSFW) inkl. Fehler an. Compare A/B blendet die gewählten Assets nebeneinander ein und hebt Parameterunterschiede hervor, Job-Status wird live per Polling nachgeladen. Forge-Modelle werden aus der Forge-API gelistet (kurzer Cache) und als Dropdown (Auto/Resolved/Fallback) angeboten.

  Die Detailansicht blendet zusätzlich Rescan-Job-Zeitpunkte (gestartet/fertig), Tag-Writes und letzte Scan-Fehler ein; die Gallery-Liste (`mediadb.php`) zeigt Scan-Zeit/Scanner plus Fehler-/Missing-Indikator kompakt an.
- **Media-Grid (Legacy)**: `media.php` bleibt als ältere Grid-Variante (Hover-Aktionen Forge/Details/Missing) erhalten, ist im UI als Legacy gekennzeichnet und nicht mehr verlinkt.
- **Einzel-Rebuild / logisches Löschen**: In `media_view.php` können einzelne Medien erneut durch die Prompt-Pipeline geschickt oder als `missing` markiert werden (keine Dateilöschung, Status-Umschaltung über `operations.php`).
- **Sicherheitsmodell**: Schreibende Webaktionen verlangen Internal-Key + IP-Whitelist; Pfadvalidierung verhindert Symlinks/Webroot-Bypass; Audit-Log dokumentiert kritische Operationen.

## Internal Access
- Internal-Key bleibt in `CONFIG/config.php` definiert und wird nicht in Klartext-Logs geschrieben.
- Einmalig `?internal_key=<key>` (GET oder POST) aufrufen reicht: Bei gültigem Key und Whitelist-IP wird der Wert für die Dauer der PHP-Session und in einem HttpOnly-Cookie hinterlegt; Folge-Requests brauchen den Parameter nicht mehr.
- IP-Whitelist bleibt bestehen; bei nicht erlaubter Quell-IP schlägt der Zugriff weiterhin fehl.
- Web-Endpunkte liefern bei fehlendem/ungültigem Key stets dieselbe kurze Antwort (`Forbidden.`), ohne Stacktraces oder Pfade. Absolute Pfade und Secrets werden in UI/JSON/Audit-Logs redigiert.

## Public vs Internal Mode
- **Public (nicht whitelisted)**: `WWW/index.php` leitet direkt auf `WWW/mediadb.php` weiter; Galerie und Detailansicht sind read-only, Operator-Panels/Schreibaktionen werden nicht gerendert.
- **Internal (whitelisted + Internal-Key)**: Dashboard bleibt Startpunkt, Operator-Aktionen sind sichtbar und nutzbar.
- **Read-only Streams**: `media_stream.php` und `thumb.php` erlauben GET-Auslieferung für reguläre Medien (`id=`) auch ohne Internal-Key, Job-Assets (`job_id`/`asset`) bleiben Internal-only.
- **POST/AJAX**: alle schreibenden Aktionen bleiben Internal-Key + IP-Whitelist-gebunden.

## Featureview & Activity-Score
- `mediadb.php` zeigt einen Featureview-Block mit den 10 Medien der niedrigsten Aktivität oberhalb der normalen Galerie.
- Aktivität steigt nur bei internen Detailaufrufen (`media_view.php` GET mit Internal-Key).
- Score-Formel (Decay in Tagen):  
  `score = clicks - floor((now - last_click_at_or_created_at) / 86400)`

### Media-Meta-Keys (media_meta)
- `activity.clicks` (int)
- `activity.last_click_at` (unix ts)
- `vote.state` (-1/0/+1)
- `curation.checked` (0/1)
- `curation.checked_at` (unix ts, optional)

### Internal-Key Required Matrix (Web)
| Endpoint | Zweck | Internal-Key |
| --- | --- | --- |
| `WWW/index.php` (Action-POSTs) | Scan/Rescan/Job-Recovery (Requeue/Cancel) | erforderlich |
| `WWW/index.php?ajax=jobs_list|job_cancel|job_delete|jobs_prune` | Scan-Job-Liste, Cancel/Delete/Prune | erforderlich |
| `WWW/media_view.php` (POST) | Forge-Regeneration, NSFW, Tags, Curation, Rescan-Job | erforderlich |
| `WWW/media_view.php?ajax=forge_jobs` | Forge-Job-Status für Detailansicht | erforderlich |
| `WWW/media_view.php?ajax=rescan_jobs` | Rescan-Status für Detailansicht | erforderlich |
| `WWW/media.php` (POST) | Forge-Regeneration/Missing-Flag | erforderlich |
| `WWW/media.php?ajax=forge_jobs` | Forge-Job-Status (Legacy-Grid) | erforderlich |
| `WWW/media.php?ajax=scan_jobs` | Scan-Job-Status (Legacy-Grid) | erforderlich |
| `WWW/media_stream.php` (`job_id`/`asset`) | Stream/Download von Job-Assets | erforderlich |
| `WWW/thumb.php` (`job_id`/`asset`) | Thumbnail-Ausgabe für Job-Assets | erforderlich |

**Beispiele für Blockierungen**
- Fehlender/ungültiger Key: `Forbidden.`
- Fehlkonfiguration oder DB-Fehler: `Server error.`
In allen Fällen: keine Pfade/Secrets in der Antwort.

## Installation / Setup
- **Voraussetzungen**: PHP 8.1+ mit PDO (SQLite/MySQL), JSON, mbstring, fileinfo, gd/imagick; ffmpeg/ffprobe für Videometadaten, Video-Thumbnails und den Selftest; optional exiftool für Metadaten. Datenbank per SQLite-File oder MySQL/MariaDB.
- **Konfiguration**: `CONFIG/config.php` definiert DB-DSN, Pfade für SFW/NSFW-Bild/Video, Logs/Temp/Backups, optionale Tool-Pfade (ffmpeg/exiftool), Scanner-Endpunkte (Base-URL, Token ODER api_key/api_key_header, Timeout, NSFW-Schwelle), Sicherheitsparameter (internal_api_key, ip_whitelist) sowie `php_cli` (absoluter PHP-CLI-Pfad für Worker-Spawns, z. B. `TOOLS/php/php.exe`).
    - Primäre Quelle ist `/mnt/data/config.php` (z. B. per Container-Volume). Falls dort nichts liegt, nutzt der Loader `CONFIG/config.php` im Repo und fällt mit Warnhinweis auf `CONFIG/config.example.php` zurück; für Deployments die Example-Datei kopieren und insbesondere `internal_api_key`/Pfad-Settings anpassen.
    - Scanner-Auth unterstützt entweder `scanner.token` (Header `Authorization: <token>`) oder das Legacy-Paar `scanner.api_key` + `scanner.api_key_header`. Bilder gehen als Multipart-Feld `image` an `/check`, Videos/GIFs als Multipart-Feld `file` an `/batch` (konfigurierbar über `scanner.batch_endpoint`, Default `/batch`).
- **Serverstart**: PHP-Builtin-Server oder Webserver auf `WWW/` zeigen; CLI-Aufrufe von `SCRIPTS/` benötigen PHP-CLI und Zugriff auf `CONFIG/config.php`. `WWW/health.php` liefert einen JSON-Healthcheck (200 + `ok/ts/version`) und wird beim Start geprüft.
- **Scanner-Verbindung**: `scan_core` ruft den konfigurierten Scanner via HTTP; Token/URL in `CONFIG/config.php` pflegen und Netzwerkzugriff sicherstellen. Bei Scanner-Fehlern werden `scan_results.raw_json` mit `http_status`, `endpoint`, `error` und Snippet gefüllt.
- **Scanner-Response Contract**: Unterstützt werden drei Response-Typen: **A** (`modules`-Objekt mit `tagging.tags`, `deepdanbooru_tags.tags`, `nsfw_scanner`), **B** (Legacy-Keys wie `modules.tagging`/`modules.nsfw_scanner`, werden in `modules` gespiegelt) und **C** (flaches JSON mit `tags`, `danbooru_tags` sowie NSFW-Klassen `hentai`/`porn`/`sexy`). Tags akzeptieren Strings oder Objekte (`label`/`name`, `score`/`confidence`/`probability`); `rating:*` in String-Listen wird als NSFW-Rating erkannt.
- **Scanner-Logs (JSONL)**: Jeder Import/Rescan schreibt JSONL-Einträge in `LOGS/scanner_ingest.jsonl` (sanitisiert/gekürzt) mit den ingest/parse/persist-Events. Upload-Metadaten (Endpoint, Feldname, Medientyp, Dateigröße, HTTP-Status) werden mitgeführt.
    - **Video-/GIF-Fallback**: Bei 4xx/5xx oder Scanner-Errors (`invalid image`/`too large`) werden temporär 3–8 Frames via FFmpeg extrahiert und über `/check` gescannt. Defaults: `scanner.video_fallback_frames=4`, `scanner.video_fallback_max_dim=1280`.

### Setup & Assets (VA/VIDAX)
- **State-Verzeichnis**: Standard `~/.va`, anpassbar über `VA_STATE_DIR`; `va install` legt u. a. `state/comfyui/workflows`, `state/comfyui/models` und `state/config` an.
- **Asset-Manifest-Suche**: Reihenfolge `VIDAX_ASSETS_CONFIG` → `<VA_STATE_DIR>/state/config/assets.json` → `config/assets.json`; dieselbe Reihenfolge gilt für `vidax.json` (umgebungsvariable `VIDAX_CONFIG` zuerst).
- **Asset-Schema**: Einträge mit `id`, `url`, `sha256`, `dest` (relativ zu `<VA_STATE_DIR>/state/`), optional `unpack`/`strip_root`; `policy.on_missing` und `policy.on_hash_mismatch` steuern Download/Abbruch.
- **CLI-Fluss**: `npx va doctor` prüft node/ffmpeg/ffprobe/python (optional) plus VIDAX-Config/Asset-Manifest und vorhandene Assets; `npx va install` erzeugt das State-Layout, kopiert Beispielconfigs und lädt/verifiziert Assets gemäß Manifest.
- **VIDAX-Serverstart**: `VIDAX_CONFIG=<pfad> node src/vidax/server.js`; ComfyUI-Pfade werden aus dem State-Verzeichnis abgeleitet. Install-Endpunkte (`/install`, `/install/status`) verlangen ein gültiges Manifest; `/jobs/:id/start` blockt bei fehlenden Assets und startet den ComfyUI-Prozess mit PID- und Log-Status unter `state/vidax/`.

### Quickstart (VA/VIDAX)
- `npm install`
- `npx va doctor` (prüft node/ffmpeg/ffprobe, python optional, plus VIDAX-Config/Assets)
- `npx va install` (legt `<VA_STATE_DIR>/state/` mit den benötigten Unterordnern an, kopiert fehlende Beispiel-Configs nach `state/config`, lädt Assets lt. Manifest)
- `VIDAX_CONFIG=<pfad>/vidax.json node src/vidax/server.js` (API-Key Pflicht; Install-Endpoints erreichbar)

## Asynchrone Scans

- Web-Trigger für Scans/Rescan legen Jobs (`scan_path`, `rescan_media`) in die Queue und starten automatisch einen dedizierten Worker im Hintergrund.
- Backfill für „ohne Tags“ läuft über den Job-Typ `scan_backfill_tags` und reiht daraus Rescan-Jobs in Chunks ein; UI-Trigger im Dashboard ist rein enqueue-only.
- Der Worker läuft rein im CLI-Kontext (`SCRIPTS/scan_worker_cli.php`) und zieht queued/running-Jobs ohne Web-Timeouts ab, Status landet in `jobs.status/forge_response_json`; `--media-id=<id>` verarbeitet gezielt einen Rescan-Job.
- Jobs mit Status `running`, die länger als ~30 Minuten kein Update erhalten, werden mit `job_stuck_timeout` auf `error` gesetzt; Scan-Jobs unterstützen Cancel/Delete/Prune (fertige Zustände).
- Beispiel: `php SCRIPTS/scan_worker_cli.php --path="/data/import" --limit=5` verarbeitet maximal fünf anstehende Scans für den angegebenen Wurzelpfad.

## Job Robustness Contract
- **Zustandsmaschine**: `queued` → `running` → `done` oder `error` (bzw. `canceled`), mit zusätzlicher Markierung `stuck-marked` (running + Timeout/Worker-Fehler). `started_at` wird genau einmal beim Übergang auf `running` gesetzt; `finished_at` wird bei `done/error/canceled` stets gesetzt und nicht überschrieben. Zeiten liegen in `jobs.forge_response_json`, UI liest sie dort aus.
- **Stuck-Regel**: Ein `running`-Job gilt als stuck, wenn `started_at` älter als `SV_JOB_STUCK_MINUTES` (~30 min) ist oder ein Worker-PID nicht mehr lebt (nach kurzer Grace-Phase). Stuck-Jobs werden als `error` markiert, erhalten `_sv_stuck` + Reason und erscheinen mit Badge/Age in Listen und Detailansicht.
- **Atomare Writes (Forge Replace)**: Output wird zuerst in eine Temp-Datei geschrieben, anschließend atomar ersetzt (gleiches Filesystem). Vor dem Replace wird ein Backup erstellt. Bei Fehlern bleibt das Original erhalten oder wird zurückkopiert; Temp/Backup werden bereinigt, Job-Error wird gesetzt.
- **Parallel-Rescan**: Tag-Replacement läuft transaktional (delete unlocked + insert), `media_tags` bleibt durch PK-Dedupe konsistent. `scan_results` nutzt run_at + id für ein eindeutiges „latest“, auch bei nahezu gleichen Timestamps.

## CLI- und Web-Operations
> Hinweis: Alle CLI-Kommandos laufen ausschließlich über `SCRIPTS/`; im `WWW/`-Verzeichnis existieren keine parallelen CLI-Dateien mehr (Legacy-Wrapper wurden entfernt). Deployments sollten sicherstellen, dass nur das bereinigte `WWW/`-Set auf dem Webserver liegt.
| Befehl/Endpoint | Zweck | Wichtige Parameter |
| --- | --- | --- |
| `php SCRIPTS/scan_path_cli.php <path> [--limit=N] [--offset=N]` | Erstimport eines Verzeichnisses, rekursiv | Pfad zur Quelle; Limits für Batches |
| `php SCRIPTS/rescan_cli.php [--limit=N] [--offset=N]` | Rescan vorhandener Medien | Batch-Steuerung |
| `php SCRIPTS/filesync_cli.php [--limit=N] [--offset=N]` | Status-Sync gegen Dateisystem | Batch-Steuerung |
| `php SCRIPTS/prompts_rebuild_cli.php [--limit=N] [--offset=N]` | Prompt-Rebuild aktiver Medien mit fehlenden Feldern | Batch-Steuerung |
| `php SCRIPTS/consistency_check.php [--repair=simple] [--limit=N] [--offset=N]` | Konsistenzprüfungen, optional einfache Reparaturen, Health-Snapshot | Repair-Modus, Batches |
| `php SCRIPTS/db_backup.php` | Manuelles Backup der DB + Metadaten-Datei mit Restore-Hinweis | Zielpfade aus `paths.backups` |
| `php SCRIPTS/migrate.php` | Führt fehlende Migrationen aus | Keine Auto-Migrationen |
| `php SCRIPTS/db_status.php` | Konsolidierter Status (Treiber, DSN, Schema-Abgleich, Migrationen) | Liefert non-zero Exit bei fehlenden Tabellen/Spalten oder offenen Migrationen |
| `php SCRIPTS/meta_inspect.php [--limit=N] [--offset=N]` | Text-Inspektor für Prompts/Metadaten | Batches |
| `php SCRIPTS/selftest_cli.php` | Lokaler Smoke-Test (PNG/MP4/Scanner-Parser, Video-Thumb) | ffmpeg/ffprobe empfohlen; Exit 2 wenn ffmpeg fehlt |
| `php SCRIPTS/cleanup_missing_cli.php [--confirm] [--no-dry-run]` | Löscht nur nach explizitem `--confirm`; Default ist Dry-Run mit ID-Listing | Locked-Tags bleiben geschützt, jede Löschung wird auditiert |
| `WWW/index.php` | Dashboard: Operator-Control-Center (Health Snapshot, Job-Center, Operator-Aktionen, Ereignisverlauf) | Internal-Key + IP-Whitelist für Write-Actions |
| `WWW/mediadb.php` | Listenansicht mit Filtern | type, prompt, meta, status, rating_min, path_substring, adult |
| `WWW/media_view.php?id=<id>` | Detailansicht eines Mediums | id (Integer), optional adult |
| `WWW/media_stream.php?id=<id>` | Streamt Originaldateien nach Pfad-Validierung | Read-only (GET), optional adult |
| `WWW/media_stream.php?job_id=<id>&asset=preview|backup|output` | Streamt Forge-Preview/Backup/Output (Pfad aus Job-Response, NSFW-Regeln via media_id) | **Internal-Key erforderlich**; job_id, asset, optional adult |
| `WWW/thumb.php?id=<id>` | Thumbnails nach Pfad-Validierung | Read-only (GET), optional adult |
| `WWW/thumb.php?job_id=<id>&asset=preview|backup|output` | Thumb aus Forge-Preview/Backup/Output (Pfad aus Job-Response, read-only Roots) | **Internal-Key erforderlich**; job_id, asset, optional adult |
> Hinweis: `SCRIPTS/consistency_check.php` beendet sich mit Exit-Code ≠ 0, sobald Findings erkannt werden (Report und Simple-Repair). Snapshot-Format (DB/Job/Scan) ist identisch in CLI und Dashboard.

## Backup/Restore
- `db_backup.php` legt neben dem SQLite-Backup (optional GZIP) eine `.meta.json` als Runtime-Artefakt mit redigiertem DSN/Pfad, Schema-Überblick (Tabellenliste, Anzahl/Sample) und Restore-Hinweis ab.
- Restore-Pfad: Anwendung stoppen, Backup-Datei an den konfigurierten DB-Pfad kopieren (bestehende Datei vorher sichern), Dienst neu starten. Die Metadaten-Datei dient als Nachweis/Beiblatt für Artefakt/Zeitpunkt.

## Konsistenz-Tools
- **UI-Indikatoren**: `mediadb.php` und `media_view.php` zeigen Badges für Prompt-Vollständigkeit, Tags und Metadaten an. Filter `incomplete=` (prompt/tags/meta/any) erleichtern die Suche nach Lücken.
- **Mini-Konsistenzcheck**: Direkt in der Detailansicht werden pro Medium die Stati (Prompt vollständig, Tags, Metadaten) angezeigt.
- **Komfort-Rebuild**: Läuft ausschließlich über die bestehenden CLI/Operations-Flows; der Dashboard-Startpunkt zeigt nur die Health- und Job-Lage.
- **Stale-Scan-Indikator**: Wenn beim Forge-Refresh kein Scanner erreichbar war, wird ein `scan_stale`-Flag in `media_meta` hinterlegt und als Badge in `mediadb.php` sowie der Detailansicht angezeigt.
- **Health Snapshot**: Dashboard und `SCRIPTS/consistency_check.php` zeigen ein kompaktes DB/Job/Scan-Health-Panel (Issues nach Check/Severity, stuck Jobs, letzte Jobs/Scans, Trigger aus Audit-Log). Der Snapshot enthält Treiber/DSN (redacted), offene Migrationen, Schema-Diff, stuck_jobs_count, letzten Job-Fehler, letzte Scan-Zeit und Scan-Job-Fehler. Die Checks erkennen u. a. Tag-Lock-Konflikte, doppelte Tag-Zuordnungen (locked/unlocked), fehlende `scan_results.run_at/scanner` bzw. fehlende Scan-Verknüpfungen, Job-State-Lücken (fehlende Zeiten/Progress/Errortext) sowie verwaiste `prompt_history`-Einträge (inkl. fehlendem Media/Version).

## Rollback-Grundlage (Prompt-Historie)
- **Persistenz**: `prompt_history` speichert pro Medium eine zeitlich geordnete Version mit Quelle (`import`, `scan`, `rescan`, `forge`) und Kernparametern. Ein Write erfolgt nur, wenn verwertbare Promptdaten vorliegen und sich gegenüber der letzten Version etwas geändert hat.
- **Referenzen**: Jeder Datensatz ist über `media_id` + `version` referenzierbar; der Link auf `prompts.id` bleibt optional. Wenn kein Prompt-Link existiert, bleibt der Datensatz als versionless (`prompt_id` NULL) erhalten, sodass kein History-Link bricht.
- **Konsistenz & Repair**: Der bestehende Konsistenz-Flow (`SCRIPTS/operations.php`) erkennt verwaiste Prompt-Links, fehlende Medien oder Versionslücken und kann diese im Simple-Repair reparieren (Links entfernen, History ohne Medium löschen, Versionen nachziehen).
- **UI-Sichtbarkeit**: `media_view.php` zeigt die letzte Prompt-Historie (Zeit, Quelle), den Status des Version-Links und eine Inkonsistenz-Badge an; es gibt keine UI-gestützte Wiederherstellung.

## Integritätsanalyse und einfache Reparatur
- **Analyse (read-only)**: `SCRIPTS/operations.php` stellt Prüfungen bereit, die fehlende Hashes, fehlende Dateien (Status `active`), Prompts ohne Roh-Metadaten, doppelte Tag-Zuordnungen (locked/unlocked), Tag-Zuordnungen ohne Confidence, fehlende `scan_results.run_at/scanner` oder komplett fehlende Scan-Verknüpfungen, Job-State-Lücken (fehlende Timestamps/Progress/Errortext) sowie verwaiste oder versionslose `prompt_history`-Verweise erkennen. Ergebnisse werden strukturiert pro Medium/Typ zurückgegeben.
- **UI-Anzeigen**: `media_view.php` listet konkrete Probleme des Mediums (erste drei Zeilen, Rest aufklappbar). `mediadb.php` bietet einen Filter `?issues=1` und markiert betroffene Medien in der Grid-Ansicht. Das Dashboard zeigt stattdessen nur den kompakten Health Snapshot.
- **Einfache Reparatur**: Läuft ausschließlich über die bestehenden Operations-Flows (CLI/Automation); das Dashboard bietet keinen separaten Repair-Button.

## Prompt-Qualität (A/B/C)
- **Zentrale Bewertung**: `SCRIPTS/operations.php` stellt eine Heuristik bereit (`sv_analyze_prompt_quality`), die Prompts in A/B/C klassifiziert, Score/Issues liefert und Tag-basierte bzw. hybride Vorschläge generiert.
- **UI-Anzeigen**: `media_view.php` zeigt die Klasse, Score, Issues (Top 3) und optionale Vorschläge (Tag-basiert/Hybrid) direkt neben dem Prompt an.
- **Filter/Badges**: `mediadb.php` bietet einen Filter `prompt_quality=A|B|C` (Alias `critical` für C) und zeigt pro Medium ein PQ-Badge mit Score/Issues an.
- **Dashboard-Summary**: Nicht Teil des Dashboard-Standards; Prompt-Qualität wird nur in Detail- und Gallery-Ansicht angezeigt.

## Forge-Regeneration
- **Async-Flow**:
  1. In der Detailansicht (`media_view.php`) „Regen über Forge“ klicken: Prompt-Heuristik (A/B/C + Tag-Fallback) läuft im Web-Request, Modell wird gegen Forge gelöst, anschließend wird nur ein Job (`jobs.type=forge_regen`) im Status `queued` angelegt.
  2. Vor dem Enqueue läuft ein synchroner Forge-Healthcheck; ist Forge nicht erreichbar, wird der Start blockiert und im Audit vermerkt.
  3. Der Web-Request stößt einen Hintergrund-Worker an (`php SCRIPTS/forge_worker_cli.php --limit=1 --media-id=<id>`), ohne auf dessen Laufzeit zu warten. Ein Cooldown-Lock (`LOGS/forge_worker_spawn.lock`, Standard 15s) verhindert Spawn-Stürme; Spawn-Ergebnis (skipped/ok/PID/Fehler) landet in `jobs.forge_response_json`.
  3. UI-Feedback: `media_view.php` blendet ein Forge-Job-Panel ein und pollt den Status per AJAX; das Dashboard (`index.php`) zeigt eine Übersicht offener/erfolgreicher/fehlerhafter Jobs. Keine Web-Requests warten auf Forge.
4. In der Grid-Ansicht (`media.php`) gibt es einen Button „Forge Regen“ pro Medium. Der Klick legt einen Job an, stößt sofort einen dedizierten Worker (`php SCRIPTS/forge_worker_cli.php --limit=1 --media-id=<ID>`) an und zeigt Job-ID, Status und Worker-PID direkt im UI (Live-Polling, keine Wartezeit im Request).
5. CLI-Worker separat/regelmäßig per Cron (`php SCRIPTS/forge_worker_cli.php --limit=1`) ausführen: Der Worker lädt Jobs der Typen `forge_regen`, `forge_regen_replace` und `forge_regen_v3` in den Status `queued`/`pending`/`created`, ruft Forge und wertet `_sv_mode` aus. Standard ist `preview` (Ergebnis landet nur als Preview-Datei), `replace` schreibt nach Backup in die Bibliothek und stößt Re-Scan/Prompt/Tag-Refresh an. Audit-/Job-Response werden in beiden Fällen gepflegt.
- Der Worker protokolliert die genutzten WHERE-Bedingungen inkl. Media-Scope, die Anzahl gefundener Jobs und loggt bei leerer Auswahl klar `No forge jobs found for media_id=<scope>, exiting` ins Runtime-Log, damit stille Exits mit `--media-id` nachvollziehbar bleiben.

**Worker-Spawn & Nachweis (Windows/Linux/macOS)**
- Spawn erfolgt non-blocking mit Lockfile (`LOGS/forge_worker_spawn.lock`, Cooldown Default 15s). Jede Anfrage schreibt eine Statuszeile (spawned/skipped/error + Grund) nach `LOGS/forge_worker_spawn.err.log`; stdout-Redirect nach `LOGS/forge_worker_spawn.out.log`.
- Windows nutzt einen absolut gequoteten Aufruf: `cmd.exe /C start "" /B "<PHP_CLI>" "<PROJECTROOT>\SCRIPTS\forge_worker_cli.php" --limit=1 --media-id=<ID> >> "<LOGS>\forge_worker_spawn.out.log" 2>> "<LOGS>\forge_worker_spawn.err.log"`. `<PHP_CLI>` bevorzugt `PHP_BINARY` (wenn `php.exe`), sonst `config[php_cli]`.
- Linux/macOS starten via `nohup` + `proc_open`-Shell und dieselbe Log-Umleitung. Fehler (z. B. fehlendes PHP) werden als `worker_spawn=error` in `jobs.forge_response_json` abgelegt und die Job-Fehlermeldung um „worker spawn failed: <snippet>“ erweitert, Status bleibt `queued`.
- Jeder CLI-Worker schreibt beim Start einen Prüf-Eintrag nach `LOGS/forge_worker_runtime.log` (Timestamp, PID, argv, media-id, limit). Job-Metadaten enthalten Spawn-Status, Kommando (ohne Secrets), Fehlersnippet (200 Zeichen) und Log-Pfade für die Nachvollziehbarkeit.
- **Replace in place**: Der Worker ersetzt die Datei auf demselben Pfad (inkl. Hash/Größe/Auflösung-Update), legt Backups an und führt danach Re-Scan/Metadaten-/Prompt-Aktualisierung durch, damit Tags/Prompts/Meta zum neuen Bild passen.
- **Pfadsicherheit**: Vor Backup/Write werden Original-, Backup-, Temp- und Zielpfad auf leere Werte geprüft; bei Fehlkonfiguration landet ein klarer Job-Error („empty path: …“) im Status und die Pfade werden im `LOGS/forge_worker_runtime.log` dokumentiert. Fehlt `paths.backups`, greift zwingend ein Fallback auf `PROJECTROOT/BACKUPS` inklusive Verzeichnisanlage.
- **Job-Verfolgung**: Die Job-Request/Response-Daten werden in `jobs.forge_request_json`/`jobs.forge_response_json` abgelegt; Statusübergänge (queued/running/done/error) bleiben auditierbar. Media-Details zeigen die letzten Jobs mit Status/Modell, das Dashboard fasst Zählungen zusammen.
- **API/Health**: Vor jedem Forge-Dispatch/Worker-Request erfolgt ein GET-Healthcheck auf `/sdapi/v1/options`; nur bei HTTP 200 wird der eigentliche Call ausgelöst. txt2img/img2img-Requests gehen direkt auf `/sdapi/v1/txt2img` bzw. `/sdapi/v1/img2img` (Basis aus `forge.base_url`), optional mit Basic Auth, aber ohne Token. Job-Responses protokollieren Ziel-URL, HTTP-Status und ein 200-Zeichen-Snippet der Antwort (keine Credentials); falls `mbstring` fehlt, wird das Snippet per `substr` gebildet, damit der Flow ohne Zusatzmodule funktioniert.
- **Deterministik & Kompatibilität**: Fehlt ein Seed in Prompt/Metadaten, wird er einmalig generiert, in `media_meta` mit Key `seed` gespeichert und für Folge-Jobs wiederverwendet. Bei fehlendem Prompt, Prompt-Qualität `C` oder unvollständigen Kernfeldern (Sampler/Scheduler/Steps/Seed/Größe/Modell) wird zwingend `img2img` mit dem Originalbild (Default-Denoise 0.25) genutzt; `txt2img` ist dann verboten. Vor jedem Request wird das Modell per `/sdapi/v1/options` gesetzt und geprüft, bei Bedarf auf das Fallback-Modell gewechselt, sonst Fehler „model resolve failed“. Sampler/Scheduler laufen über eine Fallback-Kette (Original falls vorhanden, danach `DPM++ 2M/Karras`, `Euler a/Normal`, `DPM++ SDE/Karras`); fehlerhafte Antworten oder Forge-Rejects springen automatisch zum nächsten Versuch. Die genutzte Kombination plus Attempt-Index landet in der Job-Response und wird in `media_view.php` angezeigt (inkl. Mode/Seed/Fallback-Hinweis).
- **Modellquelle & Persistenz**: Modellliste kommt autoritativ aus der Forge-API `/sdapi/v1/sd-models`, wird 90s in `LOGS/forge_models.cache.json` gecacht (stale Cache + Fallback-Modell greifen bei Fehlern) und zeigt Status/Quelle/Fehler in der Detailansicht. Jede Regeneration persistiert angefordertes/resolves Modell inkl. Quelle/Status in `jobs.forge_request_json/forge_response_json` und in den Versionen; `mediadb.php` blendet vorhandene Modellnamen als Chip/Spalte ein.
- **Preview-Speicherort**: Preview-Jobs schreiben die Ausgabe ausschließlich in `PATHS.previews` (falls gesetzt) oder nach `<BASE>/PREVIEWS` und verändern keine Bibliotheksdateien. Offensichtlich fehlerhafte oder extrem kleine Render-Ergebnisse erzwingen automatisch einen Preview-Mode, auch wenn `replace` angefordert war.
- **Format-/Cache-Pflicht**: Output muss Breite/Höhe/Ext des Originals übernehmen (JPEG/PNG/WEBP). Worker konvertiert falls nötig, markiert Abweichungen und speichert Version-Token/Hash-Wechsel; UI hängt Cache-Busting-Parameter an Thumbnails/Streams.
- **Prompt-/Negative-Kontrolle**: `media_view.php` erlaubt manuelle Prompts, manuelle Negative sowie Hybrid (Prompt + gedrosselte Tags). Negative Prompts blocken nie: explizit leere Felder per Checkbox erlauben, sonst zentraler Fallback; Flux-Modelle akzeptieren leere Negative standardmäßig. Forge-Regeneration nutzt im UI jetzt ein kompaktes Formular mit Preview als Default; Replace muss explizit gewählt werden. Seeds, Sampler, Scheduler, Denoise und Modell lassen sich vor dem Enqueue per Override setzen, ohne neue Endpunkte.
- **Mode- & Override-Flow**: `sv_decide_forge_mode()` bevorzugt `img2img` (Default-Denoise 0.25) solange ein verwertbares Quellbild vorliegt. Fehlt das Bild oder ist es ungeeignet → `txt2img`. Fehlender Prompt, Prompt-Qualität `C`, Tag-Fallback oder fehlende Kernfelder (Prompt/Modell/Sampler/Scheduler/Steps/Seed/Größe) erzwingen `img2img`; hochwertige Prompts (A/B) ohne Fallback dürfen `txt2img` nutzen. `_sv_force_txt2img` bleibt als Override. Die Override-Keys `_sv_manual_prompt`, `_sv_manual_negative`, `_sv_negative_allow_empty`, `_sv_seed`, `_sv_steps`, `_sv_denoise`, `_sv_sampler`, `_sv_scheduler`, `_sv_model` landen unverändert in `forge_request_json`; Responses spiegeln `decided_mode/reason/denoise` und die genutzten Quellen/Seeds/Sampler wider.
- **Versionen (read-only)**: `media_view.php` zeigt eine Versionsliste pro Medium. Version 0 entspricht dem Import, weitere Versionen stammen aus `forge_regen`-Jobs (Status ok/error). Sichtbar sind Zeitstempel, Quelle, gewünschtes/benutztes Modell, Prompt-Kategorie/Fallback, Hash-Wechsel sowie Backuppfad (falls vorhanden); keine Restore-Funktion. Thumbs/Streams nutzen die echten Assets aus dem jeweiligen Job (`asset=preview|backup|output`), Auswahl und Compare-Ansicht sind explizit je Asset möglich.
- **Button-Sichtbarkeit**: Forge-Regen-Buttons werden in `media.php` und `media_view.php` bei allen Bildmedien dargestellt, unabhängig von Prompt-/Konsistenzstatus oder Missing-Flag. Die Aktion erfordert weiterhin einen gültigen Internal-Key und wird serverseitig validiert; Hinweise zum Zustand erscheinen neben dem Button.

### Job-Center (Dashboard)
- `WWW/index.php` zeigt vier klare Sektionen: Running, Queued, Stuck sowie Recent Done/Error.
- Pro Job werden Typ, Medium, Start/Age, Finish, Status, Kurzfehler und vorhandene Requeue/Cancel-Aktionen angezeigt (Internal-Key/IP-Whitelist erforderlich).
- Steuerung: „Requeue“ für `error`/`done`/`canceled`, „Cancel“ für `queued`/`running`; Schreibaktionen erfordern Internal-Key/IP-Whitelist und rufen ausschließlich `SCRIPTS/operations.php`.
- Verarbeitung der Jobs bleibt beim CLI-Worker (`forge_worker_cli.php`), die Weboberfläche erzeugt oder manipuliert keine Forge-Aufrufe direkt.
- Zusätzlich listet der Scan-Jobs-Block die letzten Scan-Job-Typen (`scan_path`, `rescan_media`, `scan_backfill_tags`) mit Cancel/Delete/Prune (nur fertige Zustände für Delete/Prune).

## Hashbasierte Library, Dupes und Rename-Backfill
- **Dateiablage**: Neu importierte Medien landen hashbasiert unter `<hh>/<hash>.<ext>` (erste zwei Hex-Zeichen als Ordner). Pfade werden zentral über `sv_resolve_library_path` erzeugt.
- **Originalreferenzen**: Der ursprüngliche Importpfad/Dateiname wird als `media_meta` (`source=import`, Keys `original_path`/`original_name`) gesichert.
- **Nachpflege**: Abweichende Altbestände können im Dashboard als `library_rename`-Jobs eingeplant und via `php SCRIPTS/library_rename_worker_cli.php --limit=N` abgearbeitet werden. Der Worker verschiebt Dateien in das neue Schema, aktualisiert `media.path` und protokolliert `rename_at`.
- **Dupes**: Strikte Duplikate basieren auf identischem Hash. `mediadb.php` unterstützt die Filter `dupes=1` und `dupe_hash=<hash>` und zeigt Dupe-Badges je Hash-Gruppe.

## Bekannte Einschränkungen / Offene Baustellen
- Prompt-Historie: Prompts werden versioniert und pro Medium als Timeline angezeigt (`prompt_history`). Jede neue Persistierung (Scan/Rescan/Forge/Manual) legt einen Versionsdatensatz mit Rohtext an, ein einfacher Diff-Vergleich steht in der Detailansicht bereit.
- Snapshot-Rebuild: Prompt-Rebuild kann auf gespeicherte `media_meta`-Snapshots (`meta_key=prompt_raw`) zurückgreifen, wenn die Quelldatei fehlt; fällt sonst auf Originaldatei zurück.
- Delete-/Curation-Flows: Neue Lifecycle-/Curation-Felder (`media.lifecycle_status`, `media.quality_status` etc.) plus Event-Log (`media_lifecycle_events`). UI bietet „pending_delete“-Markierung und Curation-Flags, alles auditierbar ohne stilles Löschen. Quality-Status (`unknown/ok/review/blocked` + Score/Notes) ist klar von der Prompt-Qualität (A/B/C) getrennt und wird im UI als eigener Badge/Hinweis angezeigt.
- UI-Modernisierung teilweise umgesetzt: Die Media-Detailansicht nutzt bereits das neue Workbench-Layout; Dashboard und Listenansicht bleiben funktional, aber ohne moderne UX/JS-Verbesserungen.

## V2-Design (Kurzspezifikation)
- **Prompt-Historie**: Neue Tabelle `prompt_history` mit Versionierung pro `media_id`, referenziert `prompts.id` und speichert Raw-Text plus Normalisierung. Schreibpunkte (Scan/Rescan/Forge/Manual/Snapshot) erzeugen Versionen.
- Prompt-Historie-Writes laufen transaktional mit begrenzten Retries; Unique-Konflikte werden auditiert und führen zu einem harten Fehler statt stiller Duplikate.
- **Prompt-Rohdaten**: `prompt_raw` wird auf 20kB begrenzt; Trunkierungen werden auditiert, Versionierung erfolgt transaktional.
- **Snapshot-Rebuild**: `prompts_rebuild` nutzt bevorzugt gespeicherte `media_meta.prompt_raw`, fallback auf Quelldatei. Ohne Datei und Snapshot bleibt der Eintrag unverändert.
- **Lifecycle/Curation**: Erweiterte Felder auf `media` für `lifecycle_status`, `quality_status`, `quality_score/-notes`, `deleted_at` sowie Event-Log `media_lifecycle_events` (Statuswechsel, Delete-Requests, Quality-Evals). Kein automatisches Löschen; Statusänderungen werden protokolliert.
- **UI/Compare**: Detailansicht zeigt Prompt-Historie mit Rohdaten, einfachem Diff und manueller Auswahl von A/B-Versionen. Curation- und Delete-Formulare nutzen weiterhin Internal-Key/IP-Whitelist.
- **Security**: Neue Aktionen laufen über bestehende Internal-Key-Checks; keine zusätzlichen Web-Endpunkte, Audit via `media_lifecycle_events` + bestehendes Audit-Log.
- **Versionierung geschützt**: `prompt_history` besitzt einen Unique-Index `(media_id, version)` (Migration `20260720_001_prompt_history_unique`), History-Writes laufen transaktional mit Längenlimit auf `prompt_raw`.

## Migrationen / Setup (V2)
- Neue Migration `20260701_001_prompt_history_and_lifecycle.php` anlegen lassen (`php SCRIPTS/migrate.php`). Sie ergänzt Lifecycle-/Curation-Felder, Prompt-Historie und Lifecycle-Event-Log.
- `DB/schema.sql` enthält die neuen Tabellen/Indizes; Deployment nutzt wie gehabt manuelle Migrationen (kein Auto-DDL).
- `php SCRIPTS/db_status.php` prüft Treiber/DSN, vergleicht das erwartete Schema (Kerntabellen/-spalten) und listet offene Migrationen. Non-zero Exit signalisiert fehlende Spalten/Tabellen oder nicht eingetragene Migrationen.
- `schema_migrations` wird ausschließlich durch `SCRIPTS/migrate.php` gepflegt; einzelne Migrationen schreiben nicht selbst in diese Tabelle, Idempotenz bleibt über IF-NOT-EXISTS-DDL erhalten.
- Nach Migration optional `php SCRIPTS/prompts_rebuild_cli.php --limit=100` ausführen, um fehlende Prompts/Snapshots zu füllen (funktioniert auch ohne Quelldateien, wenn Snapshots vorhanden).

## Rauchtests (ohne externe Dienste)
- Syntaxcheck: `find SCRIPTS WWW -name '*.php' -maxdepth 3 -print0 | xargs -0 -n1 php -l`
- Minimal-DB-Init (nutzt Beispielkonfiguration, falls keine eigene vorhanden): `php SCRIPTS/init_db.php`
- Scan ohne Scanner-HTTP-Calls: `php SCRIPTS/scan_path_cli.php /tmp/import --limit=1` (leere `scanner.base_url` überspringt den externen Request)
- Thumbnail/Video-Selbsttest (ffmpeg optional, Status wird klar ausgegeben): `php SCRIPTS/selftest_cli.php`
