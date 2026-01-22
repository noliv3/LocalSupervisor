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

## 2026-01-22 — Ollama-Planung (Audit)
- Scope: **ollama**
- Status: **planned**
- Einträge: Audit-Dokumente, Schnittstellenliste, Datenverträge, Pipeline-Plan, Agenten-Dokumentation erstellt (keine Implementierung).
- Ref: n/a

## 2026-02-05 — Start-Checkup
- **Ursachenanalyse:** `start.ps1` startete `php.exe` ohne erweiterte Start/Stop-Logs; `php_server.pid` wurde bei ungültigem Inhalt/fehlendem Prozess nicht immer bereinigt. Exit-Hook nutzte fest `LOGS/` statt Config-Pfad. Keine server-startenden Aufrufe in `SCRIPTS/common.php`, `SCRIPTS/operations.php`, `SCRIPTS/init_db.php` gefunden (nur generische `shell_exec`-Aufrufe). Externe Windows-Tasks/Services/Run-Keys liegen außerhalb des Repos und wurden nicht verändert.
- **Fixes:** `php_server.pid` wird vor Start auf stale geprüft/aufgeräumt, bestehende php.exe-Prozesse werden vor Neustart gestoppt. Start/Stop-Protokollierung erweitert (PID, Commandline, CWD, Stop-Ergebnis). Exit-Hook liest LogDir via `sv_load_config` (Fallback `LOGS/`) und räumt PID-Datei. Zusätzlich Ctrl+C-Handler setzt Stop-PhpServer als Backup.
- **Geänderte Stellen in `start.ps1`:** `Start-PhpServer`, `Stop-PhpServer`, `Restart-PhpServer`, Exit-Hook-Registrierung (`PowerShell.Exiting`), Ctrl+C Hook, Cleanup-Finally.
- **Verhaltensgarantie:** Wenn `start.ps1` endet, endet `php.exe` (PID-File Cleanup + Exit-Hooks).
- **Prüfkommandos (manuell):** `powershell ./start.ps1` starten/stoppen; `Get-Process php | Select-Object Id,Path,StartTime`; `Test-Path LOGS/php_server.pid`; Ctrl+C/Window-Close prüfen (php.exe sollte beendet sein).

## 2026-03-15 — Repo-Checkup V25
**Was gefunden**
- `internal_key` wurde bei Loopback auch über HTTP persistiert; kein Opt-In für unsichere Cookie-Speicherung (`SCRIPTS/security.php`).
- Öffentliche Read-Endpoints hatten keine explizite Method-Whitelist (GET/HEAD), wodurch default-allow für andere Methoden möglich war (`WWW/mediadb.php`, `WWW/media_view.php`, `WWW/media_stream.php`, `WWW/thumb.php`).
- Job-Queue konnte ohne harte Limits weiter anwachsen; Index für `jobs(status, created_at)` fehlte; Locked-Tags-Filter ohne zusammengesetzten Index (`SCRIPTS/operations.php`, `DB/schema.sql`).
- SQLite-Pragmas für `busy_timeout`/`journal_mode` waren nicht zentral gesetzt (`SCRIPTS/db_helpers.php`, `SCRIPTS/operations.php`).

**Was geändert**
- Persistenz des `internal_key` nur noch bei HTTPS oder explizitem Opt-In (`security.allow_insecure_internal_cookie`) (`SCRIPTS/security.php`, `CONFIG/config.example.php`).
- Öffentliche Read-Endpoints whitelisten nur GET/HEAD/POST (vote_up), andere Methoden werden abgewiesen (`WWW/mediadb.php`, `WWW/media_view.php`, `WWW/media_stream.php`, `WWW/thumb.php`).
- Queue-Limits und per-Media-Guards eingeführt, Enqueue-Paths prüfen Limits vor Insert (`SCRIPTS/operations.php`, `CONFIG/config.example.php`).
- Runtime-Index-Setup ergänzt, Schema/ Migration aktualisiert (`SCRIPTS/db_helpers.php`, `SCRIPTS/migrations/003_add_runtime_indexes.php`, `DB/schema.sql`).
- SQLite-Pragmas zentralisiert und bei DB-Connect angewendet (`SCRIPTS/db_helpers.php`, `SCRIPTS/operations.php`).

**Warum**
- Schutz vor unsicherer Key-Persistenz ohne TLS und klare Public-Read-Abgrenzung.
- Queue-Overflow vermeiden und Performance für Job-/Tag-Filter verbessern.
- Stabilere SQLite-Locks und konsistente Runtime-Optimierungen.
