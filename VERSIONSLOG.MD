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

## 2026-02-05 — Start-Checkup
- **Ursachenanalyse:** `start.ps1` startete `php.exe` ohne erweiterte Start/Stop-Logs; `php_server.pid` wurde bei ungültigem Inhalt/fehlendem Prozess nicht immer bereinigt. Exit-Hook nutzte fest `LOGS/` statt Config-Pfad. Keine server-startenden Aufrufe in `SCRIPTS/common.php`, `SCRIPTS/operations.php`, `SCRIPTS/init_db.php` gefunden (nur generische `shell_exec`-Aufrufe). Externe Windows-Tasks/Services/Run-Keys liegen außerhalb des Repos und wurden nicht verändert.
- **Fixes:** `php_server.pid` wird vor Start auf stale geprüft/aufgeräumt, bestehende php.exe-Prozesse werden vor Neustart gestoppt. Start/Stop-Protokollierung erweitert (PID, Commandline, CWD, Stop-Ergebnis). Exit-Hook liest LogDir via `sv_load_config` (Fallback `LOGS/`) und räumt PID-Datei. Zusätzlich Ctrl+C-Handler setzt Stop-PhpServer als Backup.
- **Geänderte Stellen in `start.ps1`:** `Start-PhpServer`, `Stop-PhpServer`, `Restart-PhpServer`, Exit-Hook-Registrierung (`PowerShell.Exiting`), Ctrl+C Hook, Cleanup-Finally.
- **Verhaltensgarantie:** Wenn `start.ps1` endet, endet `php.exe` (PID-File Cleanup + Exit-Hooks).
- **Prüfkommandos (manuell):** `powershell ./start.ps1` starten/stoppen; `Get-Process php | Select-Object Id,Path,StartTime`; `Test-Path LOGS/php_server.pid`; Ctrl+C/Window-Close prüfen (php.exe sollte beendet sein).
