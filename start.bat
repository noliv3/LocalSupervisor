@echo off
setlocal

set BASE=I:\SuperVisOr

cd /d %BASE%
echo Starte SuperVisOr (DB-Init + PHP-Server)...

php SCRIPTS\init_db.php

php -S 127.0.0.1:8080 -t WWW

endlocal
