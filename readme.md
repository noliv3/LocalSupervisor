# Festplatten Bild/Video Verwaltungs-Tool (PHP)

Lokales PHP-Tool zur Verwaltung großer Mengen von Bildern und Videos aus ComfyUI, Stable Diffusion, Pinokio und ähnlichen Quellen.

Ziel:
- Dateien automatisiert erfassen
- Inhalte scannen (NSFW / SFW, sensible Inhalte, Danbooru-Tags)
- Tags und Prompts speichern und durchsuchbar machen
- Ü18-Inhalte sauber trennen
- Bilder per Klick mit originalen Settings über FORGE nachgenerieren


## 1. Architekturüberblick

Komponenten:

- PHP-Weboberfläche (lokal)
- Datenbank (SQLite oder MySQL/MariaDB)
- Dateisystem-Sync (Import neuer Dateien)
- Anbindung an:
  - eigenen Tag-/NSFW-Scanner (REST-API)
  - FORGE-Server zum Nachgenerieren
- Optional: weitere Scanner / Tools (z. B. ffmpeg, exiftool)


## 2. Datenmodell (Logik-Ebene)

### 2.1 Kern-Tabellen

- `media`
  - `id`
  - `path` (absoluter oder relativer Pfad)
  - `type` (image, video)
  - `source` (comfy, sd, pinokio, forge, other)
  - `width`, `height`
  - `duration` (für Videos, Sekunden)
  - `fps` (optional, für Videos)
  - `filesize`
  - `hash` (MD5/SHA1 zur Duplikaterkennung)
  - `created_at` (Dateisystem/Metadaten)
  - `imported_at` (Zeitpunkt des Eintrags)
  - `rating` (0=unrated, 1=safe, 2=questionable, 3=explicit)
  - `has_nsfw` (bool)
  - `parent_media_id` (Referenz auf Ursprung bei Varianten)
  - `status` (active, archived, deleted_logical)

- `tags`
  - `id`
  - `name`
  - `type` (content, style, character, nsfw, technical, other)
  - `locked` (bool, ob durch Auto-Scanner nicht überschrieben werden darf)

- `media_tags`
  - `media_id`
  - `tag_id`
  - `confidence` (0.0–1.0)
  - zusammengesetzter Primärschlüssel (`media_id`, `tag_id`)

- `scan_results`
  - `id`
  - `media_id`
  - `scanner` (z. B. `pixai_sensible`)
  - `run_at`
  - `nsfw_score` (gesamt)
  - `flags` (z. B. JSON: gore, nudity, violence)
  - `raw_json` (vollständige Scanner-Rückgabe)

- `prompts`
  - `id`
  - `media_id`
  - `prompt`
  - `negative_prompt`
  - `model`
  - `sampler`
  - `cfg_scale`
  - `steps`
  - `seed`
  - `width`, `height`
  - `scheduler` / `sampler_settings` (JSON)
  - `loras` (JSON)
  - `controlnet` (JSON)
  - `source_metadata` (Rohtext / Original-Parameterstring)

- `jobs` (Nachgenerierungen über FORGE)
  - `id`
  - `media_id` (Ausgangsbild)
  - `prompt_id` (verwendete Settings)
  - `type` (regenerate, variation, upscale, other)
  - `status` (queued, running, done, error)
  - `created_at`
  - `updated_at`
  - `forge_request_json`
  - `forge_response_json`
  - `error_message` (bei Fehlern)

- `collections`
  - `id`
  - `name`
  - `description`
  - `created_at`

- `collection_media`
  - `collection_id`
  - `media_id`

- `import_log`
  - `id`
  - `path`
  - `status` (imported, skipped_duplicate, error)
  - `message`
  - `created_at`


## 3. Import-Logik (Dateisystem → DB)

- Konfigurierbare Verzeichnisse, die gescannt werden:
  - Beispiel: `input/comfy`, `input/sd`, `input/pinokio`, `input/other`
- Ablauf:
  1. Verzeichnisse rekursiv durchsuchen
  2. Nur Bild- und Videoformate akzeptieren (z. B. PNG, JPG, WEBP, MP4, MKV)
  3. Dateihash berechnen zur Duplikaterkennung (`media.hash`)
  4. Metadaten auslesen:
     - Bild: Breite, Höhe, ggf. EXIF
     - Video: Dauer, Auflösung, FPS (z. B. über ffmpeg/mediainfo)
  5. Eintrag in `media` anlegen
  6. Eintrag in `import_log`

- Optional:
  - Source-spezifische Metadaten:
    - Comfy/Pinokio/Forge-Workflow-JSON
    - SD-PNG-Text-Info („parameters“)
  - Mapping auf `prompts` (Parsing und Speicherung der Settings)


## 4. Sortieren / Schieben

### 4.1 Logische Sortierung (empfohlen)

- Primäre Organisation über DB:
  - Filter nach Tags, Quelle, Rating, Datum, NSFW
  - Collections als virtuelle „Ordner“:
    - Ein Media-Eintrag kann in beliebig vielen Collections liegen.
- Ordner im Dateisystem bleiben relativ einfach:
  - z. B. nach Quelle oder nach Importdatum

### 4.2 Physische Sortierung (optional)

- Optionale Skripte, die Dateien verschieben:
  - z. B. `safe/`, `nsfw/`, `archive/`
  - oder `root/<source>/<year>/<month>/`
- Operationen:
  - Move / Copy / Hardlink
- Alle Operationen aktualisieren `media.path`


## 5. Ü18 / NSFW-Handling

- NSFW/Sensible-Infos kommen aus dem eigenen Scanner (REST-API).
- Speicherung als:
  - `scan_results.nsfw_score` und `flags`
  - Tags im `tags`/`media_tags`-System (z. B. `nsfw`, `nudity`, `gore`)
  - `media.rating` und `media.has_nsfw`

Standard-Ansicht:
- Zeigt nur `rating` = 0 oder 1

NSFW-Ansicht:
- Nur nach expliziter Freischaltung (Session-Flag oder Benutzerrolle)
- Optional physische Trennung in separate Verzeichnisse

Ziel:
- Klare Trennung von Ü18-Inhalten
- Kontrollierbare Sichtbarkeit


## 6. Tag-Erkennung / Scanner-Integration

- Eigener Tag-/NSFW-Scanner wird per HTTP aufgerufen:
  - Endpunkt z. B. `/check` oder `/batch`
  - Authentifizierung über Token/API-Key

Pipeline:
1. Nach Import von `media` wird ein Scan-Job in einer Queue vorbereitet (z. B. Tabelle oder Dateiliste).
2. Worker-Skript ruft Scanner-API auf und übergibt Datei.
3. Scanner liefert:
   - Danbooru-Tags mit Confidence
   - NSFW-Score
   - Sensible-Flags
4. Ergebnis wird in:
   - `scan_results` geschrieben
   - `tags` und `media_tags` aktualisiert
   - `media.rating` / `media.has_nsfw` gesetzt

Manuelle Nachbearbeitung:
- UI zum Hinzufügen/Entfernen von Tags
- `tags.locked = 1` verhindert Überschreiben durch automatische Scans


## 7. Prompts und Workflow-Auslesen

- Quellen:
  - PNG-Text-Felder (SD-Parameter)
  - Begleitende JSON-Dateien (Comfy, Pinokio, Forge)
- Parsing:
  - Extraktion von:
    - Prompt / negativer Prompt
    - Model
    - Sampler / Scheduler
    - CFG, Steps, Seed, Auflösung
    - LoRAs, ControlNet-Konfiguration
- Speicherung:
  - in `prompts` pro `media`
  - Referenz auf Roh-Metadaten in `source_metadata`

Ziel:
- Vollständig dokumentierte Erzeugung jedes Bildes
- Grundlage für exakte Reproduktion und Variationen


## 8. Nachgenerieren per Klick (FORGE)

UI-Funktion: Button pro Medium (z. B. „Regenerieren“ / „Variation erzeugen“).

Ablauf:
1. Klick erzeugt einen Eintrag in `jobs` mit:
   - `media_id`
   - `prompt_id`
   - Job-Typ
2. PHP erstellt HTTP-Request an FORGE-API:
   - übergibt alle relevanten Einstellungen
3. FORGE verarbeitet den Job:
   - Polling oder Callback, bis Status `done` oder `error`
4. Neue Bilder/Videos werden importiert wie normale Media-Einträge:
   - `source = forge`
   - `parent_media_id` zeigt auf das Ursprungsbild

Ergebnis:
- Variantenbaum pro Bild
- Nachvollziehbare Historie von Nachgenerierungen


## 9. UI-Funktionen

### 9.1 Übersicht / Grid

- Gitteransicht mit Thumbnails
- Basisinformationen:
  - Quelle, Rating, Tags, Erstellungsdatum

Filter:
- Tags (inkl. Mehrfachauswahl)
- Quelle (comfy, sd, pinokio, forge)
- Rating / NSFW
- Datum (Erstellungszeitraum)
- Auflösung / Seitenverhältnis
- Video-spezifisch: Dauer, FPS

### 9.2 Detailansicht

- Großes Preview (Bild/Video)
- Sichtbare Informationen:
  - Liste der Tags mit Confidence
  - Prompt + negative Prompt
  - verwendetes Model, Sampler, CFG, etc.
  - Scan-Historie (Scanner, Zeitpunkt, Score)
- Aktionen:
  - Tags bearbeiten
  - Collection-Zuordnung verwalten
  - Job an FORGE schicken

### 9.3 Batch-Operationen

- Mehrere Media-Einträge markieren
- Aktionen:
  - Tags hinzufügen/entfernen
  - in Collection aufnehmen
  - rating / has_nsfw ändern
  - physisch verschieben/archivieren
  - logical delete / Archiv-Status


## 10. Video-spezifische Funktionen

- Metadaten:
  - Dauer, Auflösung, FPS, Codec
- Thumbnail-Erzeugung:
  - z. B. aus Frame 0 oder Mitte des Videos
- NSFW-Erkennung:
  - Scanner erhält einzelne Frames oder Frame-Sampling
  - Aggregierter Score als Video-Niveau
  - Optional: Frame-level-Tags (nicht zwingend)


## 11. Sicherheit / API-Schutz

Alle internen API-Endpunkte:
- mindestens API-Key erforderlich
- optional:
  - IP-Whitelist (localhost, internes Netz)
  - einfache Auth mit Benutzer/Passwort für Web-UI

Empfohlen:
- zentrale Konfigurationsdatei (z. B. `config.php`) mit:
  - Datenbankzugang
  - API-Keys für Scanner und FORGE
  - Sicherheitseinstellungen (IP-Filter, Debug-Mode)

Ziel:
- Keine ungeschützten Endpunkte
- Keine versehentliche externe Freigabe


## 12. Logging / Fehleranalyse

- `import_log` für Dateiscans
- Logs für:
  - Scannerfehler
  - FORGE-Fehler
  - API-Fehler
- Ausgabe optional zusätzlich in Logfiles im Dateisystem

Nutzen:
- defekte Dateien erkennen
- Pfadprobleme finden
- abgestürzte Jobs nachverfolgen


## 13. Projektstatus

Dieses Dokument beschreibt:
- Zielbild des Systems
- Grundarchitektur
- Datenmodell
- benötigte Kernfunktionen

Nächste Schritte:
- Verzeichnisstruktur und Datenbanktyp festlegen
- Basis-PHP-Skeleton mit DB-Verbindung und einfachem Import-Skript
- API-Anbindung an Scanner und FORGE schrittweise integrieren
