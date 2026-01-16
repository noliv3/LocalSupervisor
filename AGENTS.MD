# AGENTS – Arbeitsregeln & Diagnose

## Zweck
Diese Datei hält **Arbeitsregeln** für Änderungen im Repository sowie eine **Diagnose-Liste** mit Auffälligkeiten fest.

## Arbeitsregeln
- **Kein Projektcode ändern**, außer es wird explizit beauftragt.
- **Dokumentation immer in README.md und AGENTS.MD pflegen**, wenn Abläufe/Schnittstellen betroffen sind.
- **Neue Dateien nur nach Auftrag**; bestehende Strukturen bevorzugen.
- **Keine Tests ausführen**, sofern nicht ausdrücklich verlangt.

## Diagnose – bekannte Auffälligkeiten
1. **Runtime-Verzeichnisse fehlen im Repo:** `LOGS/` und `BACKUPS/` sind im Arbeitsbaum nicht vorhanden und werden erst zur Laufzeit erzeugt. 
2. **Beispiel-Konfigurationen ohne echte Instanz:** Unter `config/` liegen nur `*.example.json`-Dateien; echte produktive Konfigurationsdateien sind nicht enthalten. 
3. **Vendor-Ordner im Repo:** `node_modules/` ist im Repository enthalten (erhöht Repo-Größe und erschwert Diffs).

## Diagnose – offene Punkte (ohne Codeeingriffe)
- Ein Versionen-/Änderungslog wird nun über `VERSIONSLOG.MD` geführt. Runtime-Änderungen sollten zusätzlich in `LOGS/` dokumentiert werden, sofern die Umgebung das unterstützt.

