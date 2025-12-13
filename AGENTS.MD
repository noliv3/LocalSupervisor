# AGENT RULES – SuperVisOr

## Scope
- Gilt für das gesamte Repository; keine Unterordner mit abweichenden Vorgaben.
- Erlaubt: Zielgerichtete Änderungen an PHP/SQL/Config/Docs, wenn explizit beauftragt.
- Nicht erlaubt ohne Auftrag: Änderungen an `CONFIG/`, `DB/`, `SCRIPTS/migrations/`, sicherheitsrelevanten Parametern oder Logik, die Datenfluss oder Zugriffsmodell beeinflusst.
- Gefährliche Bereiche: `CONFIG/config.php`, `SCRIPTS/migrations/*`, `DB/schema.sql`, `SCRIPTS/security.php`, `WWW/media_stream.php`, `WWW/thumb.php` (Pfadvalidierung), `SCRIPTS/paths.php`.

## Arbeitsanweisungen
- Refactor nur mit klarer Zielsetzung; keine breitflächigen Umbenennungen oder Formatierungen.
- Keine Phantom-Dateien oder temporären Ablagen anlegen; bestehende Dateien weiterverwenden.
- Keine Duplikate von CLIs, Views oder Parsern; Erweiterungen gehören in die bestehenden zentralen Komponenten.
- Keine Eingriffe ohne expliziten Auftrag; Dokumentation immer in README und AGENTS aktualisieren, wenn Abläufe/Schnittstellen betroffen sind.

## Strukturregeln
- Kernel-Logik liegt in `SCRIPTS/` (scan_core, prompt_parser, operations, logging, security, paths); Web-spezifische Logik bleibt in `WWW/`.
- Legacy-Dateien markieren statt entfernen, sofern nicht beauftragt; neue Funktionen an die bestehende Architektur anlehnen.
- Migrationen bleiben manuell; keine automatische Schemaänderung aus Web/CLI außer über `migrate.php`.
- CLI- und Wartungsskripte liegen ausschließlich unter `SCRIPTS/`; im `WWW/`-Verzeichnis sind keine CLI-/Wrapper-Skripte erlaubt. Legacy-Web-Wrapper wurden entfernt. Neue Scan/Rescan/Filesync/Prompt-Rebuild/Konsistenz-Flows müssen über `SCRIPTS/`-CLIs oder `WWW/index.php` + `SCRIPTS/operations.php` angebunden werden, nicht über zusätzliche Web-Einstiegspunkte.

## Sicherheitsregeln
- Internal-Key-Flow bewahren: Web-Schreibaktionen nur mit `sv_require_internal_access` (Header/Query/Cookie `internal_key` plus IP-Whitelist).
- Logging-Pflicht für sicherheitsrelevante Aktionen (Backups, Migrationen, Konsistenz-Reparaturen, Web-Starts von Scan/Rescan/Filesync).
- Pfadvalidierung nicht umgehen: keine Symlinks nutzen, keine Direktzugriffe außerhalb der erlaubten Media-Roots.

## Dokumentationspflichten
- Jede Codeänderung im PR-Output knapp dokumentieren (Kernstellen, keine Diff-Wände).
- README und AGENTS müssen aktuelle Abläufe, Schnittstellen und Sicherheitsregeln widerspiegeln, sobald sie geändert werden.
- Prompt-Qualität wird zentral in `SCRIPTS/operations.php` bewertet; Web-Views und Forge-Fallbacks nutzen ausschließlich diese
  Funktionen (keine eigenen Heuristiken in den PHP-Views).
- Internal-Key kann einmalig per `?internal_key=` gesetzt werden; bei gültigem Key (IP-Whitelist vorausgesetzt) wird er für die Session/als HttpOnly-Cookie gemerkt, ohne den Wert in Klartext zu loggen.
- Forge-Regen-Buttons werden bei allen Bildmedien angezeigt; Ausführung erfordert weiterhin Internal-Key/IP-Whitelist, Hinweise zum Zustand genügen.

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
- Forge läuft standardmäßig lokal auf Port 7861 (sofern die Konfiguration nichts anderes vorgibt). Vor jedem Job wird das gewünschte Modell gegen die verfügbaren Forge-Modelle geprüft; bei nicht auflösbaren Modellen greift zentral in `operations.php` ein Fallback auf `SDXL_FP16_waiNSFWIllustrious_v120.safetensors`.
- Forge-HTTP-Aufrufe nutzen die WebUI-API-Endpunkte `/sdapi/v1/options` (Healthcheck), `/sdapi/v1/txt2img` bzw. `/sdapi/v1/img2img` (Payload), optional `/sdapi/v1/progress` und verzichten auf Token-Auth; Basic Auth nur, wenn `forge.basic_auth_user/pass` gesetzt sind. Job-Logs enthalten Ziel-URL (ohne Credentials), HTTP-Status und ein 200-Zeichen-Snippet; wenn `mbstring` fehlt, greift ein `substr`-Fallback.
- Forge-Regeneration ist asynchron (Web legt Job an, CLI-Worker arbeitet ab). Prompt-Bewertung + Tag-Fallback passieren beim Enqueue; Backup/Replace/Rescan läuft nur im Worker (Replace-in-place) und schreibt Audit/Job-Status. `media_view.php` zeigt den Fortschritt per AJAX, das Dashboard fasst den Queue-Status zusammen.
- Die Grid-Ansicht `media.php` nutzt denselben Flow: Button „Forge Regen“ legt einen Job an, startet sofort einen dedizierten Worker (`forge_worker_cli.php --limit=1 --media-id=<id>`) ohne zu warten und zeigt Job-/PID-Status per Polling. Keine direkten Forge-Aufrufe aus dem Web.
- Seeds müssen bei fehlenden Prompts/Metadaten einmalig erzeugt und als `media_meta` (`meta_key=seed`) persistiert werden und in Folgejobs identisch genutzt werden. Prompt fehlt/Qualität `C`/Kernfelder (Sampler/Scheduler/Steps/Seed/Size/Model) unvollständig → strikt `img2img` mit Originalbild (`denoising_strength=0.25` Default), kein `txt2img`. Vor jedem Request Modell über `/sdapi/v1/options` setzen/prüfen; bei Fehlschlag Fallback-Modell nutzen, sonst Job-Fehler „model resolve failed“. Sampler/Scheduler-Kette: Original (falls vorhanden), dann `DPM++ 2M/Karras`, `Euler a/Normal`, `DPM++ SDE/Karras`; Versuch + genutzte Kombi in Job-Response/UI ausweisen.

## Konsistenz- und Rebuild-Funktionen
- Neue Konsistenzanzeigen oder Prompt-Rebuild-Komfortpfade müssen über `SCRIPTS/operations.php` laufen und im Dashboard/Views eingebunden werden.
- Web-Formulare dürfen keine neuen Schreibpfade öffnen, sondern bestehende Internal-Key-Mechanismen nutzen.

## Job-Management
- Jobs werden ausschließlich über `SCRIPTS/operations.php` erzeugt, gesteuert oder ausgewertet.
- Web-Schicht führt keine direkten SQL-Manipulationen auf Jobs aus, sondern ruft die Operations-Helper auf.
- Neue Job-Typen müssen ihre Steuerlogik an die Job-API in `operations.php` anbinden.

## Asynchrone Scans
- Scans laufen ausschließlich als Jobs (`scan_path`) über die Job-Queue und werden von dedizierten CLI-Workern abgearbeitet.
- Web-Einstiege (Dashboard/Media-Ansichten) dürfen Scans nur einreihen und ggf. einen Worker starten, niemals direkt scannen.
- Scanner/Import-Logik bleibt in `scan_core.php`; Worker/Queue-Handling gehört nach `operations.php` und `SCRIPTS/scan_worker_cli.php`.

## Integritäts- und Reparaturfunktionen
- Integritätsprüfungen und Reparaturen müssen vollständig über `SCRIPTS/operations.php` laufen.
- Webcode darf keine Reparaturlogik inline ausführen; er ruft ausschließlich die zentralen Operationen auf.

## Media-spezifische Operationen
- Einzelaktionen auf Medien (Prompt-Rebuild, logisches Löschen, weitere Status-Umschaltungen) werden über `media_view.php` ausgelöst und nutzen zentrale Funktionen in `SCRIPTS/operations.php`.
- Keine neuen Spezialskripte im Webroot anlegen; neue Media-Operationen gehören in `operations.php` und werden in bestehende Views eingebunden.

- Output-Format und -Größe müssen 1:1 dem Original entsprechen; Worker erzwingt Originalmaße/-Ext (JPEG/PNG/WEBP) und markiert Konvertierungen, UI nutzt Version-Token/Caches-Busting.
- Negative Prompts blocken nie: explizit leere Felder per allow_empty_negative oder Flux erlauben, sonst zentraler Fallback.
- `media_view.php` bietet pro Job manuelle Prompt-/Negative-Overrides sowie Hybrid-Prompt (Stored + Tags); Eingaben werden serverseitig begrenzt.
