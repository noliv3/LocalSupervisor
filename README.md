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

## Betriebsprinzip in einem Satz

Supervisor trennt **UI**, **Queue**, **Worker** und **Datenhaltung**, damit große Bestände stabil verarbeitet werden können, ohne dass ein einzelnes Modul (z. B. Ollama) das Gesamtsystem dominiert.

## Sicherheits- und Betriebsleitlinien

- Interne Endpunkte nur über Loopback/Whitelist + `internal_api_key`.
- Keine Secrets oder Medieninhalte in Logs/DB protokollieren.
- Schemaänderungen nur über Migrationen.
- Worker arbeiten mit Locking, Heartbeat und Recovery für hängende Jobs.

## Für wen ist Supervisor gedacht?

- Lokale Medienarchive und Content-Pipelines.
- Teams/Einzelanwender mit Bedarf an reproduzierbarer Medienverarbeitung.
- Umgebungen, die lokale Kontrolle über Daten, Modelle und Workflows benötigen.

