# Versionslog (künstlich rekonstruiert)

> Dieses Log wurde neu aufgebaut, um Funktionsupdates nachvollziehbar zu machen.

## 2025-02-14
- Job-Queue-Flow vereinheitlicht: Statuswechsel von `queued` → `running` → `done/error` als Standard beschrieben.
- Konsistenz-/Health-Snapshot als zentrales Diagnoseformat etabliert (DB/Jobs/Scans zusammengefasst).

## 2025-03-02
- Scan- und Rescan-Workflows konsolidiert: Import/Rescan laufen über zentrale CLI-Worker.
- Tagging-Logik für Locked/Unlocked-Tags als Standard dokumentiert.

## 2025-04-10
- VA/VIDAX-Setup-Flow dokumentiert (Install/Doctor/Serverstart) inklusive Node-Abhängigkeiten.
- Laufzeit-Logs und Backups als Runtime-Artefakte definiert (`LOGS/`, `BACKUPS/`).

## 2026-01-16
- Sicherheits-Helper und Web-Regeln aktualisiert (`SCRIPTS/security.php`, `WWW/index.php`, `WWW/mediadb.php`, `WWW/media_view.php`, `WWW/media_stream.php`, `WWW/thumb.php`).
- Public: Galerie/Detailansicht lesen + `vote_up` per POST; keine Scans/Rescans/Checked/Downvote/Admin.
- FSK18: Anzeige/Stream/Thumb nur intern; Query-Parameter können FSK18 für Public nicht aktivieren.
- Internal: Dashboard/Actions nur bei Loopback + `internal_api_key`.
- Session/Cookie für `internal_key` werden nur bei Loopback-Requests gesetzt.
