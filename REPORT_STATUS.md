# REPORT_STATUS

## Pipeline-Map (Ist)

### A) Import/Scan
- CLI-Einstieg `SCRIPTS/scan_path.php` lädt `CONFIG/config.php`, übernimmt `paths`/`scanner`-Konfiguration inkl. `nsfw_threshold` und ruft `sv_run_scan_path` direkt auf. 【F:SCRIPTS/scan_path.php†L4-L87】
- Wrapper `SCRIPTS/scan_path_cli.php` nutzt die zentrale Operations-Pipeline (`sv_run_scan_operation` → `sv_run_scan_path`) mit optionalem Limit. 【F:SCRIPTS/scan_path_cli.php†L1-L48】
- Dashboard `WWW/index.php` erzeugt Scan-Jobs per `sv_create_scan_job`, startet via `sv_spawn_scan_worker` einen Worker und protokolliert Audit-Infos; Rescan/Filesync/Prompt-Rebuild werden über Operations-Helfer ausgelöst. 【F:WWW/index.php†L4-L200】
- Scan-Worker `SCRIPTS/scan_worker_cli.php` lädt Config/DB und verarbeitet Queue-Einträge über `sv_process_scan_job_batch`. 【F:SCRIPTS/scan_worker_cli.php†L1-L40】
- Kernlauf `sv_run_scan_path` traversiert den Zielpfad, überspringt Systemordner und delegiert jede Datei an `sv_import_file`. 【F:SCRIPTS/scan_core.php†L1065-L1179】
- `sv_import_file` entscheidet Bild/Video, ruft den Scanner, wählt Zielpfade aus `paths.images_*`/`paths.videos_*`, speichert nach `media`, protokolliert `import_log`, legt `media_meta` für Originalpfad/-name an, schreibt `scan_results` und `tags`/`media_tags` und stößt Metadaten-/Prompt-Extraktion an. 【F:SCRIPTS/scan_core.php†L885-L1052】
- Metadaten-Extraktion nutzt `tools.ffprobe`/`tools.ffmpeg` aus der Config, liest Video-Streams aus und aktualisiert `media_meta` sowie fehlende `media`-Dimensionen (Breite/Höhe/Dauer/FPS/Size). 【F:SCRIPTS/scan_core.php†L528-L621】【F:SCRIPTS/scan_core.php†L686-L719】
- Prompt-Persistenz erfolgt in `sv_store_extracted_metadata`: Prompts werden eingefügt/ergänzt und als `raw_block` in `media_meta` gespiegelt. 【F:SCRIPTS/scan_core.php†L632-L809】
- Job-gestützte Scans: `sv_create_scan_job` legt `jobs`-Einträge mit Payload an, `sv_process_scan_job_batch` lädt queued/running Jobs und ruft `sv_run_scan_operation`, das `sv_run_scan_path` aufruft; Audit-Logging für Erfolg/Fehler ist enthalten. 【F:SCRIPTS/operations.php†L1435-L1477】【F:SCRIPTS/operations.php†L1592-L1662】【F:SCRIPTS/operations.php†L4240-L4273】
- Konfigfelder im Einsatz: Scanner-HTTP nutzt `scanner.base_url` + Auth (`token` oder `api_key`/`api_key_header`) und `scanner.timeout`/`nsfw_threshold`; Storage-Pfade kommen aus `paths.*`; Video-Metadaten nutzen `tools.ffprobe`/`tools.ffmpeg`. 【F:SCRIPTS/scan_core.php†L214-L292】【F:SCRIPTS/scan_core.php†L885-L900】【F:SCRIPTS/scan_core.php†L528-L545】

### B) Tagging
- Scanner-Request: POST auf `<base_url>/check` mit Feldern `image`, `file`, `autorefresh=1`; Header `Authorization: <token>` oder `<api_key_header>: <api_key>` werden je nach Config gesetzt. 【F:SCRIPTS/scan_core.php†L214-L263】
- Response-Parsing akzeptiert verschachtelte oder dotted Keys (`modules.nsfw_scanner`, `modules.tagging.tags`, `modules.deepdanbooru_tags.tags`), berechnet NSFW-Risk über hentai/porn/sexy oder DeepDanbooru-Rating-Tags. 【F:SCRIPTS/scan_core.php†L81-L206】
- Tag-Persistenz: `sv_store_tags` legt neue Tags an und upsertet `media_tags` mit `locked=0`; Updates erfolgen nur für nicht gesperrte Zuordnungen. 【F:SCRIPTS/scan_core.php†L295-L342】
- Import schreibt Tags/Scan-Resultate gemeinsam mit dem Medieneintrag. 【F:SCRIPTS/scan_core.php†L1019-L1049】
- Rescan (`sv_rescan_media`) leert `media_tags` mit `locked=0`, speichert neue Scanner-Tags und Scan-Resultate und aktualisiert NSFW/Rating/Status. 【F:SCRIPTS/scan_core.php†L1265-L1322】
- Forge-Refresh nutzt denselben Tag-Refresh (löscht `locked=0`, speichert Scannerdaten, setzt Media aktiv). 【F:SCRIPTS/operations.php†L3165-L3201】
- Tag-Löschpfade: `cleanup_missing_cli.php` entfernt alle `media_tags` zu `missing`-Medien ohne Locked-Filter, die Konsistenz-Reparatur löscht `confidence IS NULL`, und Orphan-Cleanup entfernt `media_tags` ohne referenzierte Media/Tags. 【F:SCRIPTS/cleanup_missing_cli.php†L44-L88】【F:SCRIPTS/operations.php†L4716-L4728】【F:SCRIPTS/operations.php†L4839-L4854】

### C) Video
- ffprobe-Extraktion speichert Format- und Stream-Keys (Dauer, Size, Bitrate, Width/Height/FPS) plus Filesize in `media_meta`, Grundlage ist `tools.ffprobe` bzw. `tools.ffmpeg`. 【F:SCRIPTS/scan_core.php†L528-L621】
- Mediadb-Grid rendert für Videos ebenfalls `<img>`-Thumbs aus `thumb.php`, zeigt Auflösung (nur Media-Dimensionen bei Videos) und Dauer-Badge, bei Bildern fällt die Auflösung auf Prompt-Width/Height zurück, falls Media-Daten fehlen. 【F:WWW/mediadb.php†L597-L655】
- Detailansicht zeigt Video-Thumbnail plus HTML5-Player mit Stream aus `media_stream.php` und Info-Bar mit Dauer/FPS/Filesize aus `media`. 【F:WWW/media_view.php†L568-L590】
- Streaming-Endpunkt validiert Pfade, liefert Content-Disposition/Type, setzt `Accept-Ranges` und bedient HTTP Range inkl. 206/416-Handling. 【F:WWW/media_stream.php†L55-L145】
- Thumbnail-Endpunkt akzeptiert `type=image|video`; für Videos wird per ffmpeg ein JPEG unter `CACHE/thumbs/video/<id>.jpg` erzeugt, 415 nur für andere Typen. 【F:WWW/thumb.php†L64-L126】【F:SCRIPTS/thumb_core.php†L1-L38】

## Diff-Status
- `git diff --stat --cached`:
```
 CHECKLIST_SMOKE.md | 12 ++++++++++++
 REPORT_GAPS.md     | 16 ++++++++++++++
 REPORT_STATUS.md   | 61 ++++++++++++++++++++++++++++++++++++++++++++++++++++++
 3 files changed, 89 insertions(+)
```
- `git diff --cached` (Auszug):
```
diff --git a/CHECKLIST_SMOKE.md b/CHECKLIST_SMOKE.md
new file mode 100644
index 0000000..574f6d8
--- /dev/null
+++ b/CHECKLIST_SMOKE.md
@@ -0,0 +1,12 @@
+# CHECKLIST_SMOKE
+
+- `php -l SCRIPTS/scan_core.php` → Erwartet: `No syntax errors` (Exit 0). Fail, wenn Parser-Fehler gemeldet.
+- `php -l SCRIPTS/scan_path.php` → Erwartet: `No syntax errors` (Exit 0). Fail bei Syntaxfehler.
+- `php -l WWW/media_stream.php` und `php -l WWW/thumb.php` → Erwartet: jeweils Exit 0 ohne Error-Ausgabe.
+- `rg "type === 'video'" WWW/thumb.php` → Erwartet: Treffer im Video-Zweig (ffmpeg-Thumb-Path). Fail, wenn kein Video-Branch vorhanden oder 415 für Video ausgelöst würde.
+- `rg "HTTP_RANGE" WWW/media_stream.php` → Erwartet: Fundstelle im Range-Handling-Block; Fail, wenn Range-Header nicht verarbeitet wird.
+- `rg "CURLFile\\(" SCRIPTS/scan_core.php` → Erwartet: Felder `image` und `file` im Scanner-Request. Fail, wenn nur eines oder keines vorkommt.
+- `rg "sv_run_scan_path" SCRIPTS/scan_path.php` → Erwartet: Funktionsaufruf vorhanden; Fail, wenn Wrapper nicht auf Kernfunktion verweist.
+- `rg "locked" DB/schema.sql` → Erwartet: Kein Treffer (zeigt fehlende Spalte, die Migration erfordert); Fail, wenn Treffer vorhanden und Analyse angepasst werden muss.
+- `sqlite3 DB/schema.sql ".schema media_tags"` (falls sqlite3 verfügbar) → Erwartet: Ausgabe ohne `locked`-Spalte, um Schema-Gap zu bestätigen; Exit ≠0 oder anderes Schema kennzeichnen.
+- Optional: `ffmpeg -version` und `ffprobe -version` (nur wenn Tools installiert) → Erwartet: Versionstext (Exit 0); Fail, wenn nicht gefunden.
```
