# SuperVisOr Security Review (Entwurf)

## Web-Angriffsfläche (aktuell)
- **Dashboard (`WWW/index.php`)**: Startet Scan/Rescan/Filesync via `cmd /C start` und `php`. Eingaben: POST `action`, optional Pfad. Schutz: `sv_require_internal_access` (Header/Query/Cookie `internal_key` + IP-Whitelist), Audit-Log mit User-Agent und Log-Datei. Risiko: platformabhängiger Start, unlimitierte Jobs, nur interner Key als Gate.
- **Mediadb (`WWW/mediadb.php`)**: Read-only Listenansicht. Eingaben: GET-Filter (`type`, `has_prompt`, `has_meta`, `q`, `status`, `min_rating`, `adult`). Schutz: Enum-/Length-Clamps, kein Write. Risiko: Pfadleck/Dateinamen sichtbar, NSFW nur via adult=1.
- **Detail (`WWW/media_view.php`)**: Read-only Detailanzeige. Eingaben: GET `id`, `adult`. Schutz: ID-Clamp, NSFW-Flag-Check. Risiko: Metadaten/Prompts sichtbar, keine Auth außer Adult-Flag.
- **Thumbnail (`WWW/thumb.php`)**: Liefert Bilder direkt aus `media.path` für Typ `image`. Eingaben: GET `id`, `adult`. Schutz: ID-Clamp, NSFW-Check, Pfadvalidierung gegen `paths.*`, MIME/resize-Logik. Risiko: Direkter Filesystem-Zugriff auf DB-Pfade (nur Images), keine Rate-Limits.
- **Media-Stream (`WWW/media_stream.php`)**: Liefert Originaldateien aus `media.path` (Bild/Video). Eingaben: GET `id`, `adult`, optional `dl=1`. Schutz: ID-Clamp, NSFW-Check, Pfadvalidierung gegen `paths.*`, Content-Disposition. Risiko: File-Download/Streaming ohne Rate-Limits, Keyless Read-Only-Zugriff.

## Sicherheitsmechanismen (IST)
- **Internal Key + IP-Whitelist**: `sv_require_internal_access` erzwingt Key-Header/Parameter/Cookie plus IP-Whitelist; CLI ist immer trusted. Fehlender/invalid Key → HTTP 403/500 + Security-Log. (`SCRIPTS/security.php`)
- **Audit-Log**: Tabelle `audit_log` mit Aktion, Entity, Details, IP, Key, Timestamp. Log wird geschrieben von Dashboard-Starts (scan/rescan/filesync), Migration, Backup, Konsistenz, ggf. Reparaturen. Fehler beim Loggen werden ignoriert (error_log). (`DB/schema.sql`, `security.php`, aufrufende CLIs/WWW)
- **Input-Härtung Web**: Filter/IDs werden per Whitelists, Integer-Clamps und Längenbegrenzungen normalisiert (mediadb, media_view, thumb). Scan-Pfad im Dashboard auf 500 Zeichen begrenzt.
- **Betriebsreihenfolge**: Empfohlen: Backup → Migration → Konsistenz (report/repair) → Cleanup Missing → produktive Läufe (Scan/Rescan/Filesync). CLI-only, dokumentiert im README/CLI-Plan.

## Risiken / Schwachstellen (Bewertung)
- **Öffentliche Read-Only-Views ohne Auth**: mediadb/media_view/thumb exponieren Pfade, Metadaten und Thumbnails für alle, sofern Netz erreichbar; nur NSFW-Flag schützt Adult-Inhalte.
- **Heavy Tasks via Dashboard**: Scan/Rescan/Filesync können ohne Limits gestartet werden; Background-Start via `cmd /C start` ist Windows-spezifisch und bietet keine Queue/Kontrolle. DoS oder unbeabsichtigte Mehrfachläufe möglich.
- **Schlüssel- und IP-Handling**: Interner Key liegt in config und muss verteilt werden; keine Rolling/Rotation-Mechanik, keine Rate-Limits, keine Sperre nach Fehlversuchen.
- **Audit-Abdeckung**: CLI-Wrapper und Maintenance-Skripte loggen, aber Read-Only-Views nicht. Fehler beim Audit-Insert werden still geloggt, nicht zurückgemeldet.
- **Pfad-/Dateizugriff**: thumb.php und media_stream.php lesen reale Dateien basierend auf DB-Pfad; Pfade werden gegen konfigurierte Basen validiert, Content-Disposition vorhanden. Keine Rate-Limits, mögliche Enumeration über ID-Ranges.
- **CLI-Exklusivität**: Kritische Tools wie `consistency_check --repair` und `cleanup_missing_cli.php` sind CLI-only; wenn ins Dashboard gebracht, erhöht sich der Impact bei Key-Leak.

## Härtungs-Zielbild / Prioritäten
1) **Zugriffstrennung**: Dashboard und Read-Only-Views nur aus internem Netz; optional IP-Whitelist auch für mediadb/media_view/thumb. Erwägen Basic-Auth oder Reverse-Proxy-Auth.
2) **Key-Disziplin**: Starken zufälligen Internal Key setzen, Rotation/Revocation-Prozess definieren, Key nie im Repo/Logs belassen; Audit bei fehlgeschlagenen Key-Checks ergänzen.
3) **Dashboard-Härtung**: Limits/Batches und Confirm-Dialoge für Scan/Rescan/Filesync; Plattformneutrale Background-Starts; optionale „report-only“ für Konsistenz, wenn Web-Start gewünscht.
4) **Audit-Vollständigkeit**: Sicherstellen, dass alle mutierenden CLIs (migrate, backup, consistency repair, cleanup) auditloggen; fehlgeschlagene Audit-Schreibversuche sichtbar machen (z. B. Warnbanner/Log-Hinweis).
5) **Read-Only-Exposure reduzieren**: Optionale Auth für mediadb/media_view/thumb oder beschränkte Export-Ansicht. Erwägen Reduktion der Pfadanzeige (z. B. nur relative Pfade) und Paging/Rate-Limits für thumb.
6) **DB-Pfadsicherheit**: Validieren/Normalisieren von `media.path` bei Import/Rescan, um ungewollte Pfade in DB zu verhindern; regelmäßige Konsistenzchecks gegen erlaubte Wurzelpfade.

## Wartungs- und Orchestrierungs-Sicht (sicherheitsfokussiert)
- **CLI-only belassen**: init_db, migrate, db_backup, consistency_check (repair), cleanup_missing_cli, exif_prompts_cli (wenn ohne Limits). Webstart höchstens für report-only Checks, nie für schema-/delete-Operationen ohne starke Absicherung.
- **Dashboard**: Nur Scan/Rescan/Filesync (ggf. mit Limits). Falls weitere Buttons: zwingend internal key + IP-Whitelist + Audit, mit Warnhinweis.
- **Standardablauf (gesichert)**: Backup → Migration → Konsistenz (report) → Cleanup Missing (nach Bestätigung) → produktive Läufe → optional EXIF/Meta-Füllung. Zwischen Schritten Audit-Einträge prüfen.

## Offene Punkte (für Abstimmung)
- Sollten mediadb/media_view/thumb hinter dieselbe IP-Whitelist/Internal-Key-Kontrolle wie das Dashboard gestellt werden?
- Ist eine plattformneutrale Hintergrundausführung (statt `cmd /C start`) geplant, und wie wird Missbrauch (Mehrfachstart) verhindert?
- Sollen fehlgeschlagene Key-Prüfungen auditiert werden, um Brute-Force-Versuche zu sehen?
- Darf `thumb.php` Zugriffe loggen/ratenbegrenzen, um Enumeration zu dämpfen?
- Wie streng sollen Importpfade validiert werden (nur bekannte Basisverzeichnisse)?
