# AGENTS – Arbeitsregeln & Diagnose

## Zweck
Diese Datei hält **Arbeitsregeln** für Änderungen im Repository sowie eine **Diagnose-Liste** mit Auffälligkeiten fest.

## Arbeitsregeln
- **Kein Projektcode ändern**, außer es wird explizit beauftragt.
- **Dokumentation immer in README.md und AGENTS.MD pflegen**, wenn Abläufe/Schnittstellen betroffen sind.
- **Neue Dateien nur nach Auftrag**; bestehende Strukturen bevorzugen.
- **Keine Tests ausführen**, sofern nicht ausdrücklich verlangt.

## Hinweis – Web-Zugriff (Dokumentation)
- Public ist auf Galerie/Detailansicht + Upvote beschränkt; alle internen Aktionen erfordern Loopback + `internal_api_key`.
- FSK18-Inhalte bleiben für Public unsichtbar (Filter/Stream/Thumb blockiert) und können nicht per Query-Parameter aktiviert werden.
- Session/Cookie-Speicherung des `internal_key` erfolgt nur bei HTTPS oder expliziter Freigabe (`security.allow_insecure_internal_cookie=true`); bei HTTP ist der Key pro Request zu senden.

## Hinweis – Job-Queue-Guards
- Queue-Limits sind konfigurierbar (`jobs.queue_max_total`, `jobs.queue_max_per_type_default`, `jobs.queue_max_per_type`, `jobs.queue_max_per_media`), damit Enqueue-Schleifen begrenzt werden.

## Hinweis – Ollama-Planung (Dokumentation)
- Für die geplante Ollama-Integration existieren separate Audit-/Interface-/Contract-Dokumente sowie eine Agentenbeschreibung unter `docs/agents/agents_ollama.md`.
- Implementierungen erfolgen erst nach Abschluss der Audit-Dokumentation und unter Beachtung der Idempotenz- und Logging-Regeln (keine Base64/Secrets in Logs).

## Hinweis – Start.ps1 Cleanup
- Start/Stop des PHP-Servers wird mit PID/Command/CWD geloggt; `php_server.pid` wird beim Beenden bereinigt.
- Exit-Hooks prüfen `LOGS/php_server.pid` sowie optional das konfigurierte Log-Verzeichnis, validieren `php` als Prozessnamen und entfernen die PID-Datei danach.

## Diagnose – bekannte Auffälligkeiten
1. **Runtime-Verzeichnisse fehlen im Repo:** `LOGS/` und `BACKUPS/` sind im Arbeitsbaum nicht vorhanden und werden erst zur Laufzeit erzeugt. 
2. **Beispiel-Konfigurationen ohne echte Instanz:** Unter `config/` liegen nur `*.example.json`-Dateien; echte produktive Konfigurationsdateien sind nicht enthalten. 
3. **Vendor-Ordner im Repo:** `node_modules/` ist im Repository enthalten (erhöht Repo-Größe und erschwert Diffs).

## Diagnose – offene Punkte (ohne Codeeingriffe)
- Ein Versionen-/Änderungslog wird nun über `VERSIONSLOG.MD` geführt. Runtime-Änderungen sollten zusätzlich in `LOGS/` dokumentiert werden, sofern die Umgebung das unterstützt.
