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

function Get-ServiceState {
    param([string]$StatePath)

    if (-not (Test-Path -Path $StatePath)) {
        return $null
    }
    try {
        $raw = Get-Content -Path $StatePath -Raw -ErrorAction SilentlyContinue
        if ([string]::IsNullOrWhiteSpace($raw)) {
            return $null
        }
        return ($raw | ConvertFrom-Json)
    } catch {
        return $null
    }
}

function Remove-ServiceState {
    param([string]$StatePath)
    try {
        if (Test-Path -Path $StatePath) {
            Remove-Item -Path $StatePath -Force -ErrorAction SilentlyContinue
        }
    } catch {
    }
}

function Get-ProcessCommandLine {
    param([int]$Pid)

    try {
        $procInfo = Get-CimInstance Win32_Process -Filter "ProcessId = $Pid" -ErrorAction Stop
        if ($null -ne $procInfo -and -not [string]::IsNullOrWhiteSpace($procInfo.CommandLine)) {
            return [string]$procInfo.CommandLine
        }
    } catch {
    }
    return ''
}

function Test-WorkerProcessMatches {
    param(
        [int]$Pid,
        [string]$ExpectedScript
    )

    if ($Pid -le 0) {
        return $false
    }

    $proc = Get-Process -Id $Pid -ErrorAction SilentlyContinue
    if ($null -eq $proc -or $proc.HasExited) {
        return $false
    }

    if ($proc.ProcessName -notin @('php', 'php.exe')) {
        return $false
    }

    $cmdLine = Get-ProcessCommandLine -Pid $Pid
    if ([string]::IsNullOrWhiteSpace($cmdLine)) {
        return $true
    }

    $expectedLeaf = [System.IO.Path]::GetFileName($ExpectedScript)
    if ([string]::IsNullOrWhiteSpace($expectedLeaf)) {
        return $true
    }

    return $cmdLine -like "*$expectedLeaf*"
}

function Stop-WorkerService {
    param(
        [hashtable]$Service,
        [string]$LogsDir
    )

    $statePath = Join-Path $LogsDir ($Service.name + '.state.json')
    $stateData = Get-ServiceState -StatePath $statePath
    if ($null -eq $stateData -or $null -eq $stateData.pid) {
        Remove-ServiceState -StatePath $statePath
        Write-Host "Service nicht aktiv: $($Service.name)"
        return
    }

    $pid = [int]$stateData.pid
    if ($pid -le 0) {
        Remove-ServiceState -StatePath $statePath
        Write-Host "Service nicht aktiv: $($Service.name)"
        return
    }

    $proc = Get-Process -Id $pid -ErrorAction SilentlyContinue
    if ($null -eq $proc -or $proc.HasExited) {
        Remove-ServiceState -StatePath $statePath
        Write-Host "Service bereits beendet: $($Service.name)"
        return
    }

    try {
        Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue
    } catch {
    }

    Start-Sleep -Milliseconds 150
    $stillAlive = Get-Process -Id $pid -ErrorAction SilentlyContinue
    if ($null -ne $stillAlive -and -not $stillAlive.HasExited) {
        Write-Host "Service konnte nicht beendet werden: $($Service.name) (PID $pid)"
    } else {
        Write-Host "Service gestoppt: $($Service.name) (PID $pid)"
    }
    Remove-ServiceState -StatePath $statePath
}

function Start-WorkerService {
    param(
        [hashtable]$Service,
        [string]$LogsDir
    )

    $stdoutLog = Join-Path $LogsDir ($Service.name + '.out.log')
    $stderrLog = Join-Path $LogsDir ($Service.name + '.err.log')
    $statePath = Join-Path $LogsDir ($Service.name + '.state.json')

    $stateData = Get-ServiceState -StatePath $statePath
    if ($null -ne $stateData -and $null -ne $stateData.pid) {
        $existingPid = [int]$stateData.pid
        if ($existingPid -gt 0) {
            if (Test-WorkerProcessMatches -Pid $existingPid -ExpectedScript $Service.script) {
                Write-Host "Service bereits aktiv: $($Service.name) (PID $existingPid)"
                return
            }

            $strayProc = Get-Process -Id $existingPid -ErrorAction SilentlyContinue
            if ($null -ne $strayProc -and -not $strayProc.HasExited) {
                Write-Host "Service-State inkonsistent, stoppe Prozess: $($Service.name) (PID $existingPid)"
                try { Stop-Process -Id $existingPid -Force -ErrorAction SilentlyContinue } catch {}
            }
        }
        Remove-ServiceState -StatePath $statePath
    }

    Rotate-LogFile -Path $stdoutLog -Keep 10
    Rotate-LogFile -Path $stderrLog -Keep 10

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

$logsDir = Get-LogsDir
$services = @(
    @{ name = 'scan_service'; script = 'SCRIPTS\scan_service_cli.php'; args = @('--require-web=http://127.0.0.1:8080/health.php', '--require-web-miss=3') },
    @{ name = 'forge_service'; script = 'SCRIPTS\forge_service_cli.php'; args = @('--require-web=http://127.0.0.1:8080/health.php', '--require-web-miss=3') },
    @{ name = 'media_service'; script = 'SCRIPTS\media_service_cli.php'; args = @('--require-web=http://127.0.0.1:8080/health.php', '--require-web-miss=3') },
    @{ name = 'library_rename_service'; script = 'SCRIPTS\library_rename_service_cli.php'; args = @('--require-web=http://127.0.0.1:8080/health.php', '--require-web-miss=3') },
    @{ name = 'ollama_service'; script = 'SCRIPTS\ollama_service_cli.php'; args = @('--require-web=http://127.0.0.1:8080/health.php', '--require-web-miss=3') }
)

switch ($Action) {
    'start' {
        foreach ($svc in $services) {
            Start-WorkerService -Service $svc -LogsDir $logsDir
        }
    }
    'stop' {
        foreach ($svc in $services) {
            Stop-WorkerService -Service $svc -LogsDir $logsDir
        }
    }
    'restart' {
        foreach ($svc in $services) {
            Stop-WorkerService -Service $svc -LogsDir $logsDir
        }
        foreach ($svc in $services) {
            Start-WorkerService -Service $svc -LogsDir $logsDir
        }
    }
    default {
        Write-Host "Unbekannte Action: $Action (unterstützt: start|stop|restart)"
        exit 1
    }
}
