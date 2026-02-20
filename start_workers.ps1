param(
    [string]$Action = 'start'
)

$scriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($scriptRoot)) {
    $scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
}

$base = $env:SV_BASE
if ([string]::IsNullOrWhiteSpace($base)) {
    $base = $scriptRoot
}
$base = (Resolve-Path -Path $base).Path
Set-Location $base

$phpExe = Join-Path $base "TOOLS\php\php.exe"
if (-not (Test-Path -Path $phpExe)) {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -ne $phpCmd) {
        $phpExe = $phpCmd.Source
    }
}
if (-not (Test-Path -Path $phpExe)) {
    Write-Host "php.exe nicht gefunden (TOOLS\\php oder PATH)."
    exit 1
}

$ini = Join-Path $base "TOOLS\php\php.ini"
$phpArgs = @()
if (Test-Path -Path $ini) {
    $phpArgs += @('-c', $ini)
}

function Get-LogsDir {
    $logDir = Join-Path $base 'LOGS'
    try {
        $cfgLog = & $phpExe @phpArgs -r 'require "SCRIPTS/common.php"; require "SCRIPTS/logging.php"; $cfg = sv_load_config(); echo sv_logs_root($cfg);' 2>$null
        if ($LASTEXITCODE -eq 0 -and -not [string]::IsNullOrWhiteSpace($cfgLog)) {
            $logDir = $cfgLog.Trim()
        }
    } catch {
    }
    if (-not (Test-Path -Path $logDir)) {
        New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    }
    return $logDir
}

function Rotate-LogFile {
    param(
        [string]$Path,
        [int]$Keep = 5
    )

    for ($i = $Keep - 1; $i -ge 1; $i--) {
        $src = "$Path.$i"
        $dst = "$Path.$($i + 1)"
        if (Test-Path -Path $src) {
            Move-Item -Path $src -Destination $dst -Force
        }
    }
    if (Test-Path -Path $Path) {
        Move-Item -Path $Path -Destination "$Path.1" -Force
    }
}

function Start-WorkerService {
    param(
        [hashtable]$Service,
        [string]$LogsDir
    )

    $stdoutLog = Join-Path $LogsDir ($Service.name + '.out.log')
    $stderrLog = Join-Path $LogsDir ($Service.name + '.err.log')
    $statePath = Join-Path $LogsDir ($Service.name + '.state.json')

    Rotate-LogFile -Path $stdoutLog -Keep 5
    Rotate-LogFile -Path $stderrLog -Keep 5

    $args = @()
    if ($phpArgs.Count -gt 0) {
        $args += $phpArgs
    }
    $args += @($Service.script)
    if ($Service.ContainsKey('args') -and $Service.args -is [System.Array]) {
        $args += $Service.args
    }

    $proc = Start-Process -FilePath $phpExe -ArgumentList $args -WorkingDirectory $base -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog -WindowStyle Hidden -PassThru

    $state = [ordered]@{
        service = $Service.name
        pid = $proc.Id
        started_at = (Get-Date).ToString('o')
        log_paths = [ordered]@{
            stdout = $stdoutLog
            stderr = $stderrLog
        }
        script = $Service.script
        args = $args
    }
    $state | ConvertTo-Json -Depth 6 | Set-Content -Path $statePath -Encoding UTF8

    Write-Host "Service gestartet: $($Service.name) (PID $($proc.Id))"
}

if ($Action -ne 'start') {
    Write-Host "Unbekannte Action: $Action (unterstützt: start)"
    exit 1
}

$logsDir = Get-LogsDir
$services = @(
    @{ name = 'scan_service'; script = 'SCRIPTS\scan_service_cli.php'; args = @() },
    @{ name = 'forge_service'; script = 'SCRIPTS\forge_service_cli.php'; args = @() },
    @{ name = 'media_service'; script = 'SCRIPTS\media_service_cli.php'; args = @() },
    @{ name = 'library_rename_service'; script = 'SCRIPTS\library_rename_service_cli.php'; args = @() }
)

foreach ($svc in $services) {
    Start-WorkerService -Service $svc -LogsDir $logsDir
}
