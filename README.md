# Supervisor (LocalSupervisor)

Supervisor ist eine lokale Plattform für Medienverwaltung und Medienverarbeitung.
Sie bündelt **Import, Katalogisierung, Analyse, Ableitungen und Betrieb** in einem System – mit Web-Oberfläche, Job-Queue und modularen Workern.

> Kurz gesagt: Dateien rein, Supervisor organisiert sie, reichert Metadaten an und macht die Verarbeitung planbar.

## Was das Projekt kann (Kernfeatures)

- **Medienkatalog für Bilder & Videos**
  - Zentrale Erfassung mit Metadaten, Hashes, Tags, Ratings und technischen Informationen.
- **Skalierbarer Import/Scan**
  - Ordnerbasierter Import mit Queue-gestützter Abarbeitung und nachvollziehbaren Logs.
- **Job-System mit Worker-Modulen**
  - Einheitliche Queue für Hintergrundjobs (Scan, Forge, Ollama, Media-Tasks).
- **Web-UI für Betrieb und Sichtung**
  - Listenansicht, Detailansicht, Streams/Previews, Health- und Admin-Endpunkte.
- **KI- und Automationsmodule (optional)**
  - Ollama-Pipeline für Caption/Tags/Qualität/NSFW/Embeddings.
  - Forge-Pipeline für rezeptbasierte Generierungs- oder Transformationsjobs.
- **Datenqualität & Nachvollziehbarkeit**
  - Konsistenzprüfungen, Audit-Log, Import-Log und Fehlerklassen pro Job.

## Enthaltene Bereiche (Portfolio-Übersicht)

### 1) Web & Bedienung
- `WWW/mediadb.php` – Medienliste mit Filterung.
- `WWW/media_view.php` – Detailseite pro Medium.
- `WWW/health.php` – Healthcheck.
- `WWW/jobs_prune.php` – Job-Bereinigung.
- Interne Routen (`dashboard_ollama.php`, `internal_ollama.php`) für lokalen Admin-Betrieb.

### 2) Worker & Automatisierung
- Scan-Worker (`scan_path_cli.php`, `scan_worker_cli.php`)
- Media-Worker (`media_worker_cli.php`, z. B. Integrity/Hash/Derivate)
- Forge-Worker (`forge_worker_cli.php`)
- Ollama-Service/Worker (`ollama_service_cli.php`, `ollama_worker_cli.php`)
- Persistente Worker-Services (`scan_service_cli.php`, `media_service_cli.php`, `forge_service_cli.php`, `library_rename_service_cli.php`)
- Betriebs-CLI für Jobs/DB/Konsistenz (`jobs_admin.php`, `db_status.php`, `consistency_check.php`, ...)

### 3) Daten & Konfiguration
- `DB/schema.sql` + Migrationen als führende Datenbasis.
- `CONFIG/config.example.php` als Startpunkt der Instanzkonfiguration.
- Ergänzende Vorlagen unter `config/*.example.json`.

### 4) Integrationen
- **Ollama**: Lokale LLM-gestützte Metadatenanreicherung.
- **Forge**: Rezeptgesteuerte Job-Erzeugung und Verarbeitung.
- **VIDAX/Node-Komponenten**: Ergänzende Runtime-Werkzeuge (`bin/va.js`).

## Schnellstart (kompakt)

1. Konfiguration anlegen
```bash
cp CONFIG/config.example.php CONFIG/config.php
```
2. Datenbank initialisieren
```bash
php SCRIPTS/init_db.php
php SCRIPTS/migrate.php
```
3. Web starten
```bash
php -S 127.0.0.1:8080 -t WWW
```
4. Beispiel-Worker starten
```bash
php SCRIPTS/scan_worker_cli.php --limit=50
php SCRIPTS/media_worker_cli.php --limit=50
```

## Persistente Worker-Services (Phase 1)

- Die Services `scan_service_cli.php`, `forge_service_cli.php`, `media_service_cli.php` und `library_rename_service_cli.php`
  laufen als dauerhafte Hintergrundprozesse.
- Jeder Service arbeitet in kurzen Batches und geht bei `0` verarbeiteten Jobs in einen Idle-Sleep (`--sleep-ms` oder Config-Fallback).
- Jeder Service schreibt einen eigenen Heartbeat nach `LOGS/<service>.heartbeat.json` mit minimalen Feldern (`ts`, `pid`, `state`).
- SIGTERM/SIGINT wird sauber behandelt; der Service beendet den Loop kontrolliert.

### Starten unter Windows (detached)

```powershell
powershell -ExecutionPolicy Bypass -File .\start_workers.ps1
```

- `start_workers.ps1` startet alle Worker-Services detached via `Start-Process`.
- Pro Service werden Rolling-Logs geführt (Rotation mit Backups):
  - `LOGS/<service>.out.log`
  - `LOGS/<service>.err.log`
- Bei Batch-Fehlern bleibt der Heartbeat während des Backoff-Sleeps auf `error`, damit Healthchecks den Fehlzustand klar sehen.
- Worker-Events werden als JSONL in `LOGS/worker_events.jsonl` geschrieben (z. B. `batch_exception`).
- `LOGS/system_errors.jsonl` bleibt als zentrales Fehlerlog erhalten, wird aber pro `(worker_type,error_code)` zeitlich gedrosselt.
- Pro Service wird eine State-Datei geschrieben:
  - `LOGS/<service>.state.json` mit `pid`, `started_at`, `log_paths`.


## Web-Requests sind non-blocking (Phase 2)

- Web-Endpunkte (`WWW/index.php`, `WWW/mediadb.php`, `WWW/media_view.php`, `WWW/ollama.php`) führen im Request-Pfad **keine Worker-Spawns** aus.
- HTTP-Aktionen sind auf **enqueue / cancel / status-read** ausgelegt; die Abarbeitung erfolgt über bestehende CLI-Worker/Services.
- Default-Konfiguration:
  - `workers.web_spawn_enabled = false`
  - `migrations.web_enabled = false`
- Migrationen und Worker-Starts bleiben für CLI/Admin-Flows vorgesehen (`SCRIPTS/migrate.php`, Worker-CLI/Services).
- Spawn-Logs für Worker sind auf feste Dateien konsolidiert (`scan_worker_spawn.out/err.log`, `forge_worker_spawn.out/err.log`) statt timestamp-basierter Einzelfiles.

## Betriebsprinzip in einem Satz

Supervisor trennt **UI**, **Queue**, **Worker** und **Datenhaltung**, damit große Bestände stabil verarbeitet werden können, ohne dass ein einzelnes Modul (z. B. Ollama) das Gesamtsystem dominiert.

## Sicherheits- und Betriebsleitlinien

- Interne Endpunkte nur über Loopback/Whitelist + `internal_api_key`.
- Keine Secrets oder Medieninhalte in Logs/DB protokollieren.
- Schemaänderungen nur über Migrationen.
- Worker arbeiten mit Locking, Heartbeat und Recovery für hängende Jobs.

## Harte Voraussetzungen & bekannte Stopper

Die folgenden Punkte sind **harte Betriebsbedingungen**. Wenn einer davon nicht erfüllt ist,
kommt es zu sofortigen Fehlern oder zu einem nicht funktionsfähigen Teilsystem.

1. **CLI-Skripte nur per CLI starten**
   - Skripte in `SCRIPTS/` (z. B. `selftest_cli.php`) sind für `PHP_SAPI === 'cli'` ausgelegt.
   - Ein Aufruf über den Browser (HTTP/Web-SAPI) führt bewusst zu einem sofortigen Abbruch.

2. **Beispielkonfiguration reicht nicht für den Betrieb**
   - Nach der Installation muss mindestens `CONFIG/config.example.php` in `CONFIG/config.php` übernommen und angepasst werden.
   - Ohne produktive Konfigurationsdateien bleibt das System nicht betriebsfähig.

3. **Systemabhängigkeiten für Medienverarbeitung müssen installiert sein**
   - Das Doctor-Setup (`src/setup/doctor.js`) meldet fehlende Tools wie `ffmpeg`, `ffprobe` oder `magick` als kritisch.
   - Ohne diese Programme funktionieren zentrale Medien- und Thumbnail-Prozesse nicht vollständig.
   - Fehlen `ffmpeg` oder `ffprobe`, endet der Doctor-Lauf mit Exit-Code `20`.

4. **PHP-GD ist für den Selbsttest verpflichtend**
   - Der Selbsttest verwendet Bildoperationen (`imagecreatetruecolor`) zur Verifikation.
   - Ist die GD-Erweiterung nicht geladen, bricht `selftest_cli.php` sofort mit `GD nicht verfügbar` ab.

5. **Vidax-Konfiguration und Asset-Manifests müssen vorhanden sein**
   - Fehlende Vidax-Config (`config/vidax*.json`) oder fehlende Asset-Manifests werden vom Doctor als kritischer Fehler gewertet.
   - Auch in diesem Fall endet der Doctor-Lauf mit Exit-Code `20`.

6. **Pfadkonfiguration muss auf existierende, beschreibbare Verzeichnisse zeigen**
   - `LIBRARY_PATH` und `THUMB_PATH` müssen vorhanden und für den Prozessbenutzer schreibbar sein.
   - Zusätzlich müssen `LOG_PATH` und das DB-Verzeichnis beschreibbar sein; sonst schlagen Logging, Persistenz und Importe fehl.

7. **Datenbankstruktur muss initialisiert und konsistent sein**
   - `init_db.php` und `migrate.php` sind Pflichtschritte vor produktivem Betrieb.
   - Bei fehlendem/inkonsistentem Schema schlagen DB-Operationen fehl (u. a. sichtbar in `selftest_cli.php` als `FAILED`).

8. **Ollama nur mit laufendem Dienst und verfügbaren Modellen**
   - Ollama-Funktionen (Analyse, Tagging, Vision-Workflows) benötigen einen erreichbaren lokalen Ollama-Service.
   - Zusätzlich müssen die benötigten Modelle tatsächlich vorhanden/geladen sein (z. B. `llava`, `llama3`, `nomic-embed-text`).

9. **Web-Pfad bleibt non-blocking für Worker und Migrationen**
   - Worker-Starts und Migrationen werden im HTTP-Request-Pfad nicht ausgeführt.
   - Direkte Web-Aktionen für diese Schritte sind standardmäßig deaktiviert und als CLI/Admin-Flow vorgesehen.

## Für wen ist Supervisor gedacht?

- Lokale Medienarchive und Content-Pipelines.
- Teams/Einzelanwender mit Bedarf an reproduzierbarer Medienverarbeitung.
- Umgebungen, die lokale Kontrolle über Daten, Modelle und Workflows benötigen.
