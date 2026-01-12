# Status-Notizen

- Neue Config-Key `php_cli` (absoluter PHP-CLI-Pfad) wird für Worker-Spawn genutzt; Fallback auf `TOOLS/php/php.exe` und `php` im PATH.
- Start/Update werden über `LOGS/start.lock` und `LOGS/update.lock` serialisiert; PHP-Server-Logs landen in `LOGS/php_server.out.log`/`LOGS/php_server.err.log`.
- Healthcheck-Endpunkt: `WWW/health.php` liefert `200` + JSON `{ok:true, ts, version}` und wird beim Start geprüft.
