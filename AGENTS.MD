# AGENT RULES – SuperVisOr

## Scope
- Gilt für das gesamte Repository; keine Unterordner mit abweichenden Vorgaben.
- Erlaubt: Zielgerichtete Änderungen an PHP/SQL/Config/Docs, wenn explizit beauftragt.
- Nicht erlaubt ohne Auftrag: Änderungen an `CONFIG/`, `DB/`, `SCRIPTS/migrations/`, sicherheitsrelevanten Parametern oder Logik, die Datenfluss oder Zugriffsmodell beeinflusst.
- Gefährliche Bereiche: `CONFIG/config.php`, `SCRIPTS/migrations/*`, `DB/schema.sql`, `SCRIPTS/security.php`, `WWW/media_stream.php`, `WWW/thumb.php` (Pfadvalidierung), `SCRIPTS/paths.php`.
- Keine neuen Repository-Dateien oder Ordner anlegen; bestehende Strukturen nutzen.

## Arbeitsanweisungen
- Refactor nur mit klarer Zielsetzung; keine breitflächigen Umbenennungen oder Formatierungen.
- Keine Phantom-Dateien oder temporären Ablagen anlegen; bestehende Dateien weiterverwenden.
- Keine Duplikate von CLIs, Views oder Parsern; Erweiterungen gehören in die bestehenden zentralen Komponenten.
- Keine Eingriffe ohne expliziten Auftrag; Dokumentation immer in README und AGENTS aktualisieren, wenn Abläufe/Schnittstellen betroffen sind.
- Forge-Modellquelle: Liste stammt aus `/sdapi/v1/sd-models`, wird kurz (~90s) in `LOGS/forge_models.cache.json` gecacht (stale Cache + Fallback-Modell erlaubt), Jobs/Versionen speichern requested/resolved Modell inkl. Quelle/Status; UI zeigt Quelle/Fehler transparent an.
- Config-Zugriff immer über `sv_load_config()`; der Loader fällt bei fehlender `CONFIG/config.php` mit Warnung auf `CONFIG/config.example.php` zurück. Keine direkten `require` auf `CONFIG/config.php` hinzufügen.
- Config-Priorität: `/mnt/data/config.php` (z. B. Container-Volume) hat Vorrang vor `CONFIG/config.php`; Fallback auf `CONFIG/config.example.php` bleibt mit Warnhinweis bestehen.

## Strukturregeln
- Kernel-Logik liegt in `SCRIPTS/` (scan_core, prompt_parser, operations, logging, security, paths); Web-spezifische Logik bleibt in `WWW/`.
- Legacy-Dateien markieren statt entfernen, sofern nicht beauftragt; neue Funktionen an die bestehende Architektur anlehnen.
- Migrationen bleiben manuell; keine automatische Schemaänderung aus Web/CLI außer über `migrate.php`.
- `schema_migrations` wird ausschließlich von `SCRIPTS/migrate.php` gepflegt; einzelne Migrationen dürfen keinen eigenen Insert mehr ausführen.
- CLI- und Wartungsskripte liegen ausschließlich unter `SCRIPTS/`; im `WWW/`-Verzeichnis sind keine CLI-/Wrapper-Skripte erlaubt. Legacy-Web-Wrapper wurden entfernt. Neue Scan/Rescan/Filesync/Prompt-Rebuild/Konsistenz-Flows müssen über `SCRIPTS/`-CLIs oder `WWW/index.php` + `SCRIPTS/operations.php` angebunden werden, nicht über zusätzliche Web-Einstiegspunkte.

## Sicherheitsregeln
- Internal-Key-Flow bewahren: Web-Schreibaktionen nur mit `sv_require_internal_access` (Header/Query/Cookie `internal_key` plus IP-Whitelist).
- Internal-Key gilt zusätzlich für Stream/Thumb und Job-Status-AJAX (kein anonymer Zugriff auf interne Medien- oder Jobdaten).
- Logging-Pflicht für sicherheitsrelevante Aktionen (Backups, Migrationen, Konsistenz-Reparaturen, Web-Starts von Scan/Rescan/Filesync).
- Pfadvalidierung nicht umgehen: keine Symlinks nutzen, keine Direktzugriffe außerhalb der erlaubten Media-Roots.
- Forge-Preview/Backup/Output werden in `thumb.php`/`media_stream.php` ausschließlich über `job_id` + `asset` (preview|backup|output) aufgelöst; Pfadfreigabe nur über Preview-/Backup-Roots, NSFW-Check bleibt an `media_id` gekoppelt.
- Fehler-/Audit-Ausgaben und UI dürfen keine Secrets, Tokens, DSNs oder absoluten Pfade enthalten; Antworten sind kurz und einheitlich (`Forbidden.` bzw. `Server error.`).

### Internal-Key Required Matrix (Web)
| Endpoint | Zweck | Internal-Key |
| --- | --- | --- |
| `WWW/index.php` (Action-POSTs) | Scan/Rescan/Filesync/Prompt-Rebuild/Konsistenz | erforderlich |
| `WWW/media_view.php` (POST) | Forge-Regeneration, NSFW, Tags, Quality, Rescan-Job | erforderlich |
| `WWW/media_view.php?ajax=forge_jobs` | Forge-Job-Status | erforderlich |
| `WWW/media_view.php?ajax=rescan_jobs` | Rescan-Status | erforderlich |
| `WWW/media.php` (POST) | Forge-Regeneration/Missing-Flag | erforderlich |
| `WWW/media.php?ajax=forge_jobs` | Forge-Job-Status (Legacy-Grid) | erforderlich |
| `WWW/media.php?ajax=scan_jobs` | Scan-Job-Status (Legacy-Grid) | erforderlich |
| `WWW/media_stream.php` | Stream/Download | erforderlich |
| `WWW/thumb.php` | Thumbnails/Assets | erforderlich |

## Dokumentationspflichten
- Jede Codeänderung im PR-Output knapp dokumentieren (Kernstellen, keine Diff-Wände).
- README und AGENTS müssen aktuelle Abläufe, Schnittstellen und Sicherheitsregeln widerspiegeln, sobald sie geändert werden.
- Prompt-Qualität wird zentral in `SCRIPTS/operations.php` bewertet; Web-Views und Forge-Fallbacks nutzen ausschließlich diese
  Funktionen (keine eigenen Heuristiken in den PHP-Views).
- Internal-Key kann einmalig per `?internal_key=` gesetzt werden; bei gültigem Key (IP-Whitelist vorausgesetzt) wird er für die Session/als HttpOnly-Cookie gemerkt, ohne den Wert in Klartext zu loggen.
- Health-Snapshot-Standard: DB/Job/Scan-Health kommt aus `operations.php` (Issues nach Check/Severity, stuck Jobs, letzte Jobs/Scans, Trigger aus Audit-Log); neue Prüfungen dort anhängen, Format in Dashboard/CLI beibehalten. Snapshot enthält Treiber, redigierten DSN, offene Migrationen/Schema-Diff, stuck_jobs_count, letzte Job-Fehler sowie letzte Scan-Zeit/Scan-Job-Errors.
- `consistency_check.php` liefert Exit-Code ≠ 0 bei Findings (Report und Simple-Repair); Snapshot-Ausgabe in CLI/Dashboard ist strukturgleich.
- `db_backup.php` schreibt neben dem SQLite-/GZIP-Backup eine `.meta.json` (redacted DSN/Pfad, Tabellenliste + Schema-Überblick, Restore-Hinweis); Restore erfolgt durch Stopp des Dienstes und Kopie des Backups an den DB-Pfad (Bestand sichern).
- Neuer VA/VIDAX-Setup-Flow: `va doctor` (Runtime-Checks), `va install` (StateDir + Assets, kopiert fehlende Example-Configs nach `state/config`), VIDAX-Install-Endpunkte (`/install`, `/install/status`, `/jobs/:id/start` blockiert ohne Assets). Änderungen daran immer in README.md dokumentieren (keine separaten docs/SETUP.md oder docs/ASSETS.md vorhanden).
- Forge-Regen-Buttons werden bei allen Bildmedien angezeigt; Ausführung erfordert weiterhin Internal-Key/IP-Whitelist, Hinweise zum Zustand genügen.
- Prompt-Historie und Snapshot-Rebuild: `prompt_history` hält Versionen inkl. Raw-Text, Schreibpfade (Scan/Rescan/Forge/Snapshot-Rebuild) müssen die Historie füllen. Prompt-Rebuild nutzt vorhandene `media_meta.prompt_raw`-Snapshots, wenn Originaldateien fehlen, sonst Originaldatei. UI zeigt Timeline + einfachen Diff; keine Restore-Funktion.
- Lifecycle/Quality: `media.lifecycle_status` (`active/review/pending_delete/deleted_logical`) und `media.quality_status` (`unknown/ok/review/blocked`) dürfen nur über bestehende Operations-/Web-Formulare gesetzt werden, immer auditierbar (Eintrag in `media_lifecycle_events`). Keine stillen Deletes; `missing` bleibt Status-Flag ohne Dateilöschung.
- cleanup_missing_cli nur mit `--confirm` löschen; Default ist Dry-Run mit ID-Listing, jede Löschung wird auditiert.
- Prompt-Historie besitzt einen Unique-Index (media_id, version); Writes laufen transaktional, `prompt_raw` wird auf 20kB begrenzt (Trunkierung wird auditiert). Versionskonflikte müssen auditiert werden und dürfen keine stillen Duplikate erzeugen.
- Forge-Healthcheck läuft vor Web-Start von Forge-Regen; bei fehlender Erreichbarkeit wird der Start blockiert und auditiert.
- Wenn Forge-Refresh ohne Scanner läuft, wird `media_meta.scan_stale=1` gesetzt und als Badge in Grid/Detail angezeigt; beim nächsten erfolgreichen Scan wird der Flag entfernt.

## Hashbasierte Library / Dupes
- Importiert wird nur noch in das Schema `<hh>/<hash>.<ext>` innerhalb der konfigurierten Roots (`sv_resolve_library_path`).
- Ursprüngliche Pfade landen als `media_meta` (`original_path`, `original_name`), ext-Mismatches als Meta-Eintrag.
- Library-Rename-Jobs (`library_rename`) laufen ausschließlich per CLI-Worker und müssen Pfadvalidierung/Auditlog nutzen.
- Dupe-Gruppen basieren auf identischem Hash und sind in `mediadb.php` filter- und sichtbar.

## Versions-/Historienanzeige
- Versionen werden rein aus bestehenden Jobs/Metadaten/Backups abgeleitet (keine neuen Tabellen oder Schreibpfade).
- Die UI zeigt eine lesende Liste der Versionen; Restore-/Rollback-Flows gehören zentral nach `SCRIPTS/operations.php` und sind separat zu designen.

## Forge-Regeneration
- Forge-bezogene Operationen laufen ausschließlich über `SCRIPTS/operations.php` (Job-Erzeugung/Dispatch) und `WWW/media_view.php` (UI-Auslöser).
- Neue Forge-Funktionen müssen das `jobs`-Modell verwenden und auditierbar sein; keine direkten Forge-Helper im Webroot anlegen.
- Auto-Start des Forge-Workers erfolgt non-blocking aus der Webschicht mit Cooldown-Lock unter `LOGS/forge_worker_spawn.lock` (Standard 15s), Spawn-Ergebnis landet in `jobs.forge_response_json`.
- Spawn schreibt immer in `LOGS/forge_worker_spawn.err.log` (Status spawned/skipped/error mit Grund) und `LOGS/forge_worker_spawn.out.log`; Jobs speichern `worker_spawn`, Kommando, Snippet und Log-Pfade. Jeder Worker hinterlegt beim Start einen Eintrag in `LOGS/forge_worker_runtime.log`.
- Forge-Worker akzeptieren `forge_regen`/`forge_regen_replace`/`forge_regen_v3` in den Status `queued`/`pending`/`created`; Auswahl-Scopes (Media-ID/WHERE-Klausel/Job-Anzahl) werden geloggt und leere Trefferlisten landen mit Klartext („No forge jobs found for media_id=<scope>, exiting“) im Runtime-Log.
- Forge läuft standardmäßig lokal auf Port 7861 (sofern die Konfiguration nichts anderes vorgibt). Vor jedem Job wird das gewünschte Modell gegen die verfügbaren Forge-Modelle geprüft; bei nicht auflösbaren Modellen greift zentral in `operations.php` ein Fallback auf `SDXL_FP16_waiNSFWIllustrious_v120.safetensors`.
- Forge-HTTP-Aufrufe nutzen die WebUI-API-Endpunkte `/sdapi/v1/options` (Healthcheck), `/sdapi/v1/txt2img` bzw. `/sdapi/v1/img2img` (Payload), optional `/sdapi/v1/progress` und verzichten auf Token-Auth; Basic Auth nur, wenn `forge.basic_auth_user/pass` gesetzt sind. Job-Logs enthalten Ziel-URL (ohne Credentials), HTTP-Status und ein 200-Zeichen-Snippet; wenn `mbstring` fehlt, greift ein `substr`-Fallback.
- Forge-Regeneration ist asynchron (Web legt Job an, CLI-Worker arbeitet ab). Prompt-Bewertung + Tag-Fallback passieren beim Enqueue; Backup/Replace/Rescan läuft nur im Worker (Replace-in-place) und schreibt Audit/Job-Status. `media_view.php` zeigt den Fortschritt per AJAX, das Dashboard fasst den Queue-Status zusammen.
- Forge-Regeneration nutzt `_sv_mode`: Standard `preview` (Ergebnis als Preview außerhalb der Media-Roots), optional `replace` mit Backup/Replace/Refresh. Verdächtige Outputs (getimagesize-Fehler, <10KB etc.) werden immer auf Preview downgegradet.
- Forge-Worker müssen Pfade hart validieren: Original-/Backup-/Temp-/Zielpfad dürfen nie leer sein (Job-Error "empty path: <name>"); die Pfadwahl wird vor Schreiboperationen im Runtime-Log festgehalten. Fehlt `paths.backups`, gilt immer `PROJECTROOT/BACKUPS` als Fallback inklusive Verzeichnisanlage.
- Die Grid-Ansicht `media.php` nutzt denselben Flow: Button „Forge Regen“ legt einen Job an, startet sofort einen dedizierten Worker (`forge_worker_cli.php --limit=1 --media-id=<id>`) ohne zu warten und zeigt Job-/PID-Status per Polling. Keine direkten Forge-Aufrufe aus dem Web.
- Seeds müssen bei fehlenden Prompts/Metadaten einmalig erzeugt und als `media_meta` (`meta_key=seed`) persistiert werden und in Folgejobs identisch genutzt werden. Prompt fehlt/Qualität `C`/Kernfelder (Sampler/Scheduler/Steps/Seed/Size/Model) unvollständig → strikt `img2img` mit Originalbild (`denoising_strength=0.25` Default), kein `txt2img`. Vor jedem Request Modell über `/sdapi/v1/options` setzen/prüfen; bei Fehlschlag Fallback-Modell nutzen, sonst Job-Fehler „model resolve failed“. Sampler/Scheduler-Kette: Original (falls vorhanden), dann `DPM++ 2M/Karras`, `Euler a/Normal`, `DPM++ SDE/Karras`; Versuch + genutzte Kombi in Job-Response/UI ausweisen.
- Mode-Entscheidung: `sv_decide_forge_mode` bleibt img2img-first; txt2img nur ohne nutzbares Quellbild, bei `_sv_force_txt2img` oder hochwertigen Prompts (A/B) ohne Fallback. Prompt-Missing, Kategorie C, Tag-Fallback oder fehlende Kernfelder (Prompt/Modell/Sampler/Scheduler/Steps/Seed/Größe) erzwingen img2img (Default-Denoise 0.25).
- Mode-/Override-Pflicht: `sv_decide_forge_mode` entscheidet deterministisch `img2img`/`txt2img` (Default img2img + Denoise 0.25; txt2img nur bei `_sv_force_txt2img` oder ungeeignetem Input). Overrides `_sv_manual_prompt`, `_sv_manual_negative`, `_sv_negative_allow_empty`, `_sv_seed`, `_sv_steps`, `_sv_denoise`, `_sv_sampler`, `_sv_scheduler`, `_sv_model`, `_sv_mode` bleiben unverändert im Request und werden im Worker/UI angezeigt (decided_mode/reason/denoise, used_prompt/negative_source, seed/sampler/scheduler).

## Konsistenz- und Rebuild-Funktionen
- Neue Konsistenzanzeigen oder Prompt-Rebuild-Komfortpfade müssen über `SCRIPTS/operations.php` laufen und im Dashboard/Views eingebunden werden.
- Web-Formulare dürfen keine neuen Schreibpfade öffnen, sondern bestehende Internal-Key-Mechanismen nutzen.

## Job-Management
- Jobs werden ausschließlich über `SCRIPTS/operations.php` erzeugt, gesteuert oder ausgewertet.
- Web-Schicht führt keine direkten SQL-Manipulationen auf Jobs aus, sondern ruft die Operations-Helper auf.
- Neue Job-Typen müssen ihre Steuerlogik an die Job-API in `operations.php` anbinden.
- Laufende Jobs, die länger als ~30 Minuten kein Update erhalten, werden automatisch mit `job_stuck_timeout` auf `error` gesetzt (aktuell für Forge/Scan/Rescan).

## Asynchrone Scans
- Scans laufen ausschließlich als Jobs (`scan_path`) über die Job-Queue und werden von dedizierten CLI-Workern abgearbeitet.
- Web-Einstiege (Dashboard/Media-Ansichten) dürfen Scans nur einreihen und ggf. einen Worker starten, niemals direkt scannen.
- Scanner/Import-Logik bleibt in `scan_core.php`; Worker/Queue-Handling gehört nach `operations.php` und `SCRIPTS/scan_worker_cli.php`.
- Einzel-Rescan läuft als Job (`rescan_media`) und wird ebenfalls über `scan_worker_cli.php` abgearbeitet (`--media-id=<id>` unterstützt).

## Integritäts- und Reparaturfunktionen
- Integritätsprüfungen und Reparaturen müssen vollständig über `SCRIPTS/operations.php` laufen.
- Webcode darf keine Reparaturlogik inline ausführen; er ruft ausschließlich die zentralen Operationen auf.

## Media-spezifische Operationen
- Einzelaktionen auf Medien (Prompt-Rebuild, logisches Löschen, weitere Status-Umschaltungen) werden über `media_view.php` ausgelöst und nutzen zentrale Funktionen in `SCRIPTS/operations.php`.
- Keine neuen Spezialskripte im Webroot anlegen; neue Media-Operationen gehören in `operations.php` und werden in bestehende Views eingebunden.

- Output-Format und -Größe müssen 1:1 dem Original entsprechen; Worker erzwingt Originalmaße/-Ext (JPEG/PNG/WEBP) und markiert Konvertierungen, UI nutzt Version-Token/Caches-Busting.
- Negative Prompts blocken nie: explizit leere Felder per allow_empty_negative oder Flux erlauben, sonst zentraler Fallback.
- `media_view.php` bietet pro Job manuelle Prompt-/Negative-Overrides sowie Hybrid-Prompt (Stored + Tags); Eingaben werden serverseitig begrenzt.

## UI Map (Operator-Startpunkte)
- **Startpunkt (Galerie)**: `WWW/mediadb.php` ist die einzige produktive Galerie-UI (Card-Grid + List-Mode).
- **Operator-Dashboard**: `WWW/index.php` bündelt Scan/Rescan/Filesync/Prompt-Rebuild, Health Snapshot und Job-Übersicht.
- **Detailansicht**: `WWW/media_view.php` für Einzelmedium (Forge/Rescan/Tags/Quality).
- **Legacy-Pfad (nicht verlinkt)**: `WWW/media.php` bleibt nur für Übergang/Alt-Workflows erreichbar und ist im UI als Legacy gekennzeichnet.
- **Nicht mehr nutzen**: Links/Navigation, die `media.php` als Standardzugang anbieten.

## Laufzeit-Notizen (aktuell)
- Scanner-HTTP liegt zentral in `scan_core.php` und akzeptiert `scanner.token` oder Legacy `scanner.api_key`/`scanner.api_key_header`; Dateien gehen als `image` und `file` raus.
- Parser liest verschachtelte oder gepunktete Modul-Keys; NSFW bleibt `null` wenn kein Modul greift.
- `media_tags.locked` schützt manuelle Tags: Rescan/Regeneration löschen nur Einträge mit `locked=0`.
- Video-Unterstützung: `thumb.php` erzeugt JPEG-Thumbs via ffmpeg, `media_stream.php` liefert HTTP-Range, UI zeigt Video-Thumb+Player.
- `SCRIPTS/selftest_cli.php` prüft Import/Parser/Video-Thumb; ffmpeg/ffprobe nötig für den Video-Teil (Exit 2 wenn ffmpeg fehlt).
- Rescan-Job persistiert `scan_results.run_at` + Scanner + Fehler im `scan_results.raw_json`, ersetzt deduplizierte `media_tags` nur für `locked=0`, räumt `scan_stale` nach Erfolg und auditiert Erfolg/Fehler; UI pollt den Rescan-Status (inkl. started/finished_at) und zeigt letzten Scan (Zeit/Scanner/NSFW/Rating/Tags + Fehler) sowie Scan-Missing/Fehler-Indikatoren in Detail- und Gallery-Ansicht.
- Forge-Modelle kommen aus der Forge-API (Cache `LOGS/forge_models.cache.json`, ca. 120s) und stehen als Dropdown (Auto/Resolved/Fallback) bereit.
- Quality-Status (`unknown/ok/review/blocked` + Score/Notes) ist klar von Prompt-Qualität (A/B/C) getrennt; UI zeigt eigene Badges/Hinweise.

## Konsolidierte Audits & Pläne
- **Repository-Landkarte**: `CONFIG/` bündelt zentrale Konfigurationen, `DB/` enthält das Referenzschema, `SCRIPTS/` bildet Kernel/CLIs/Migrationen ab, `WWW/` deckt Dashboard, Listen, Detailansichten sowie Stream/Thumb ab; Laufzeitpfade `LOGS/`, `BACKUPS/`, `TOOLS/` entstehen im Betrieb.
- **Sicherheitslage**: Web-Schreibpfade verlangen Internal-Key + IP-Whitelist und Audit-Logs; Pfadvalidierung in `media_stream.php`/`thumb.php` ist kritisch, Symlinks bleiben verboten. Scanner-Antworten gelten als untrusted; optionales Rate-Limiting kann ergänzt werden.
- **CLI-Aufräumplan**: Kern-CLIs (`scan_path_cli.php`, `rescan_cli.php`, `filesync_cli.php`, `migrate.php`, `db_backup.php`, `consistency_check.php`, `cleanup_missing_cli.php`, `init_db.php`) bleiben bestehen. Read-only-Metatools (`exif_prompts_cli.php`, `meta_inspect.php`, `db_inspect.php`, `show_prompts_columns.php`) sind optional. Legacy-Wrapper (`scan_path.php`, `sync_media_cli.php`) nur mit klarer Deprecation/Entfernung anfassen. Parameterkonvention: `path`, `--limit`, `--offset`, `--dry-run` (wo sinnvoll), `--repair` für Reparaturläufe.
- **DB-Gesundheit**: `SCRIPTS/db_status.php` liefert den verbindlichen Konsistenz-/Migrationsstatus (Treiber, DSN-Redaktion, fehlende Tabellen/Spalten, offene Migrationen). Exit-Code ≠0 signalisiert Handlungsbedarf; neue Checks gehören in dieses Skript, nicht ins Web.
- **Pipeline-Snapshot**: Scan/Rescan/Filesync laufen über `operations.php` + Worker (`scan_worker_cli.php`), Import nutzt `sv_import_file` und schreibt `media`/`scan_results`/`tags`/`media_meta`/`prompts`. Tagging ersetzt nur `locked=0`; Cleanup Missing und einfache Konsistenz-Reparaturen lassen `locked=1` unangetastet. Video-Pfad liefert ffmpeg-Thumbnails, HTTP-Range-Streaming und Grid-/Detailanzeigen.
- **Gaps/Prioritäten**: (1) `media_tags.locked` bleibt Pflichtfeld im Schema/Migration; (2) Weitere Skripte auf Config-Fallback (`sv_load_config`) und Lock-Respekt prüfen; (3) UI-Modernisierung und Prompt-Historie bleiben offen.
- **Detailseite (Stand jetzt)**: `media_view.php` bietet einen Version-Switch (inkl. Strategy/Mode-Auswahl über `_sv_recreate_strategy`), NSFW-Schalter via `sv_set_media_nsfw_status` (verschiebt Pfade in passende Roots), Tag-Editor/Rescan (locked=1 bleibt geschützt) sowie Compare A/B für Versionen. Forge/Rescan/NSFW/Tag-Aktionen laufen weiter über Internal-Key/IP-Whitelist und Audit-Log.
