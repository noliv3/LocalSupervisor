$base   = "I:\SuperVisOr"
$phpExe = Join-Path $base "TOOLS\php\php.exe"
$ini    = Join-Path $base "TOOLS\php\php.ini"

Set-Location $base

Write-Host "Starte SuperVisOr (DB-Init + PHP-Server)..."

& $phpExe -c $ini "SCRIPTS\init_db.php"
& $phpExe -c $ini "-S" "127.0.0.1:8080" "-t" "WWW"
