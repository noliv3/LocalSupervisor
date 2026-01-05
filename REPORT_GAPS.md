# REPORT_GAPS

## Tagging / Scanner
- Feldname/Auth/Response-Mismatch: nicht beobachtet – Request sendet sowohl `image` als auch `file`, akzeptiert `Authorization` oder konfigurierbaren API-Key-Header, Parser versteht dotted und verschachtelte Modul-Keys. 【F:SCRIPTS/scan_core.php†L214-L292】【F:SCRIPTS/scan_core.php†L81-L206】
- Schema-Divergenz: Basisschema `media_tags` besitzt kein `locked`, während alle Schreibpfade das Feld voraussetzen oder filtern. Ohne Migration schlägt das Upsert (`locked`-Spalte) fehl und Locks können nicht greifen. 【F:DB/schema.sql†L45-L64】【F:SCRIPTS/scan_core.php†L295-L342】
- Locked-Tag-Verlust: `cleanup_missing_cli` löscht sämtliche `media_tags` zu `missing`-Einträgen, Konsistenz-Reparatur entfernt `confidence IS NULL`, und Orphan-Cleanup löscht Beziehungen – alle ohne Locked-Schutz. 【F:SCRIPTS/cleanup_missing_cli.php†L44-L88】【F:SCRIPTS/operations.php†L4716-L4728】【F:SCRIPTS/operations.php†L4839-L4854】

## Video / Streaming
- `thumb.php` unterstützt Videos (ffmpeg-JPEG, 415 nur für andere Typen); kein offensichtlicher 415-Miss für Videos. 【F:WWW/thumb.php†L89-L126】
- Grid nutzt `<img>`-Thumbs auch für Videos (JPEG aus `thumb.php`), Resolution-Badge bei Videos basiert ausschließlich auf gespeicherten Media-Dimensionen; Bilder fallen ersatzweise auf Prompt-Dimensionen zurück. 【F:WWW/mediadb.php†L597-L655】
- Streaming setzt `Accept-Ranges` und bedient Range-Header, Seek-Unterstützung ist vorhanden. 【F:WWW/media_stream.php†L92-L145】

PRIORITY FIX ORDER
1. Medien-Tag-Schema angleichen (`media_tags.locked` sicherstellen bzw. Migration durchziehen), damit Tag-Upserts/Locks nicht an fehlender Spalte scheitern. 
2. Tag-Löschpfade (`cleanup_missing_cli`, Konsistenz-Reparatur, Orphan-Cleanup) um Locked-Respekt erweitern, um manuelle Tags nicht zu verlieren. 
3. Nach Migration prüfen, ob weitere Skripte `media_tags` ohne Locked-Filter anfassen, um konsistente Sperrsemantik zu sichern. 
