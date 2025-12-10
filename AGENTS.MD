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

## Media-spezifische Operationen
- Einzelaktionen auf Medien (Prompt-Rebuild, logisches Löschen, weitere Status-Umschaltungen) werden über `media_view.php` ausgelöst und nutzen zentrale Funktionen in `SCRIPTS/operations.php`.
- Keine neuen Spezialskripte im Webroot anlegen; neue Media-Operationen gehören in `operations.php` und werden in bestehende Views eingebunden.
