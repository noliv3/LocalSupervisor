# SECURITY REVIEW – SuperVisOr

## Ziel des Sicherheitsmodells
Schreibende Aktionen (Scan/Rescan/Filesync/Prompt-Rebuild/Migration/Backup/Reparaturen) dürfen nur von internen Operatoren ausgelöst werden; Medienzugriff erfolgt ausschließlich über validierte Pfade. Logs/Audit-Einträge müssen jede sicherheitsrelevante Aktion nachvollziehbar machen.

## Angriffsflächen
- **Web-Zugänge**: Dashboard-Formulare und Listen/Detail-Views; Missbrauch durch ungeprüfte Parameter oder fehlende Authentisierung.
- **media_stream / thumb**: Direkter Dateizugriff nach Pfadprüfung; fehlerhafte Validierung würde Webroot-/Symlink-Bypass ermöglichen.
- **Scanner → HTTP**: Kommunikation zum externen Scanner (Token/Timeouts); Antworten können untrusted Metadaten liefern.
- **Datenbank**: Manipulation über SQL-Injection oder fehlende Schreibkontrollen; Migrationen/Backups mit hoher Wirkung.

## Sicherheitsregeln
- **Internal-Key**: Schreibende Web-Einstiege nur mit gültigem `internal_key` im Header/Query/Cookie **und** IP in der Whitelist; ansonsten 403 + Security-Log.
- **IP-Whitelist**: Eingeschränkte Quell-IP-Liste in `CONFIG/config.php`; Änderungen nur bewusst vornehmen.
- **Audit-Log**: Migrationen, Backups, Reparaturen, Web-Starts von Scan/Rescan/Filesync/Prompt-Rebuild protokollieren IP/Key/Action.
- **Rate-Limits (optional)**: Für spätere Erweiterungen vorsehen, um Web-Buttons und Stream-Endpunkte gegen Abuse abzusichern.

## Pfadmodell
- **Media-Roots**: SFW/NSFW für Bild und Video werden in `paths.*` konfiguriert und ausschließlich über `paths.php` bereitgestellt.
- **Verbot von Symlinks**: Keine Symlink-basierten Freigaben; Dateien werden direkt aus den konfigurierten Verzeichnissen gestreamt.
- **Pfadvalidierung**: `media_stream.php` und `thumb.php` müssen Pfade strikt gegen die erlaubten Wurzeln prüfen und Normalisierung/Traversal verhindern.

## Empfohlene Hardening-Schritte
- Internal-Key/IP-Whitelist strikt durchziehen; keine neuen Web-Schreibpfade ohne `sv_require_internal_access`.
- Scanner-Antworten als untrusted behandeln; Parser normalisieren und sensible Felder nicht ungeprüft übernehmen.
- Optionales Rate-Limiting für Web-Buttons und Streaming ergänzen.
- Audit-Log-Abdeckung prüfen und bei neuen Wartungsaktionen erweitern; Log-Rotation beibehalten.
- Keine Strukturänderung am Schema ohne manuelle Migration; Pfad-/Security-Checks nicht umgehen.
