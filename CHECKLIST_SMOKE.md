# CHECKLIST_SMOKE

- `php -l SCRIPTS/scan_core.php` → Erwartet: `No syntax errors` (Exit 0). Fail, wenn Parser-Fehler gemeldet.
- `php -l SCRIPTS/scan_path.php` → Erwartet: `No syntax errors` (Exit 0). Fail bei Syntaxfehler.
- `php -l WWW/media_stream.php` und `php -l WWW/thumb.php` → Erwartet: jeweils Exit 0 ohne Error-Ausgabe.
- `rg "type === 'video'" WWW/thumb.php` → Erwartet: Treffer im Video-Zweig (ffmpeg-Thumb-Path). Fail, wenn kein Video-Branch vorhanden oder 415 für Video ausgelöst würde.
- `rg "HTTP_RANGE" WWW/media_stream.php` → Erwartet: Fundstelle im Range-Handling-Block; Fail, wenn Range-Header nicht verarbeitet wird.
- `rg "CURLFile\\(" SCRIPTS/scan_core.php` → Erwartet: Felder `image` und `file` im Scanner-Request. Fail, wenn nur eines oder keines vorkommt.
- `rg "sv_run_scan_path" SCRIPTS/scan_path.php` → Erwartet: Funktionsaufruf vorhanden; Fail, wenn Wrapper nicht auf Kernfunktion verweist.
- `rg "locked" DB/schema.sql` → Erwartet: Kein Treffer (zeigt fehlende Spalte, die Migration erfordert); Fail, wenn Treffer vorhanden und Analyse angepasst werden muss.
- `sqlite3 DB/schema.sql ".schema media_tags"` (falls sqlite3 verfügbar) → Erwartet: Ausgabe ohne `locked`-Spalte, um Schema-Gap zu bestätigen; Exit ≠0 oder anderes Schema kennzeichnen.
- Optional: `ffmpeg -version` und `ffprobe -version` (nur wenn Tools installiert) → Erwartet: Versionstext (Exit 0); Fail, wenn nicht gefunden.
