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

    $normalizedCmd = $cmdLine.ToLowerInvariant()
    $expectedFull = (Join-Path $base $ExpectedScript).ToLowerInvariant().Replace('/', '\\')
    $expectedLeaf = [System.IO.Path]::GetFileName($ExpectedScript).ToLowerInvariant()

    if ($normalizedCmd -notmatch '(^|\s|")php(\.exe)?(\s|$)') {
        return $false
    }

    return ($normalizedCmd -like "*$expectedFull*") -or ($normalizedCmd -like "*$expectedLeaf*")
}


function Find-WorkerPidByScript {
    param([string]$ExpectedScript)

    $expectedFull = (Join-Path $base $ExpectedScript).ToLowerInvariant().Replace('/', '\')
    $expectedLeaf = [System.IO.Path]::GetFileName($ExpectedScript).ToLowerInvariant()

    try {
        $candidates = Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='php'" -ErrorAction SilentlyContinue
        if ($null -eq $candidates) {
            return $null
        }

        foreach ($procInfo in $candidates) {
            $cmdLine = ''
            if ($null -ne $procInfo -and $null -ne $procInfo.CommandLine) {
                $cmdLine = [string]$procInfo.CommandLine
            }
            if ([string]::IsNullOrWhiteSpace($cmdLine)) {
                continue
            }
            $normalizedCmd = $cmdLine.ToLowerInvariant()
            if ($normalizedCmd -notmatch '(^|\s|")php(\.exe)?(\s|$)') {
                continue
            }
            if (($normalizedCmd -like "*$expectedFull*") -or ($normalizedCmd -like "*$expectedLeaf*")) {
                return [int]$procInfo.ProcessId
            }
        }
    } catch {
    }

    return $null
}

function Write-ServiceState {
    param(
        [hashtable]$Service,
        [string]$StatePath,
        [int]$Pid,
        [string]$StdoutLog,
        [string]$StderrLog,
        [array]$Args
    )

    $state = [ordered]@{
        service = $Service.name
        pid = $Pid
        started_at = (Get-Date).ToUniversalTime().ToString('o')
        log_paths = [ordered]@{
            stdout = $StdoutLog
            stderr = $StderrLog
        }
        script = $Service.script
        args = $Args
    }
    $state | ConvertTo-Json -Depth 6 | Set-Content -Path $StatePath -Encoding UTF8
}

function Reconcile-ServiceState {
    param(
        [hashtable]$Service,
        [string]$LogsDir
    )

    $statePath = Join-Path $LogsDir ($Service.name + '.state.json')
    $stdoutLog = Join-Path $LogsDir ($Service.name + '.out.log')
    $stderrLog = Join-Path $LogsDir ($Service.name + '.err.log')

    $args = @()
    if ($phpArgs.Count -gt 0) {
        $args += $phpArgs
    }
    $args += @($Service.script)
    if ($Service.ContainsKey('args') -and $Service.args -is [System.Array]) {
        $args += $Service.args
    }

    $stateData = Get-ServiceState -StatePath $statePath
    if ($null -ne $stateData -and $null -ne $stateData.pid) {
        $statePid = [int]$stateData.pid
        if ($statePid -le 0 -or -not (Test-WorkerProcessMatches -Pid $statePid -ExpectedScript $Service.script)) {
            Remove-ServiceState -StatePath $statePath
            Write-Host "Service-State bereinigt: $($Service.name)"
            $stateData = $null
        }
    }

    if ($null -eq $stateData) {
        $runningPid = Find-WorkerPidByScript -ExpectedScript $Service.script
        if ($null -ne $runningPid -and $runningPid -gt 0) {
            Write-ServiceState -Service $Service -StatePath $statePath -Pid $runningPid -StdoutLog $stdoutLog -StderrLog $stderrLog -Args $args
            Write-Host "Service-State rekonstruiert: $($Service.name) (PID $runningPid)"
        }
    }
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

    if (-not (Test-WorkerProcessMatches -Pid $pid -ExpectedScript $Service.script)) {
        Write-Host "Service-Stop übersprungen (Commandline-Mismatch): $($Service.name) (PID $pid)"
        return
    }

    try {
        Stop-Process -Id $pid -ErrorAction SilentlyContinue
    } catch {
    }

    $graceMs = 2500
    $slept = 0
    while ($slept -lt $graceMs) {
        Start-Sleep -Milliseconds 250
        $slept += 250
        $probe = Get-Process -Id $pid -ErrorAction SilentlyContinue
        if ($null -eq $probe -or $probe.HasExited) {
            break
        }
    }

    $stillAlive = Get-Process -Id $pid -ErrorAction SilentlyContinue
    if ($null -ne $stillAlive -and -not $stillAlive.HasExited) {
        try { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue } catch {}
        Start-Sleep -Milliseconds 150
        $stillAlive = Get-Process -Id $pid -ErrorAction SilentlyContinue
    }

    if ($null -ne $stillAlive -and -not $stillAlive.HasExited) {
        Write-Host "Service konnte nicht beendet werden: $($Service.name) (PID $pid)"
    } else {
        Write-Host "Service gestoppt: $($Service.name) (PID $pid)"
        Remove-ServiceState -StatePath $statePath
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

    Write-ServiceState -Service $Service -StatePath $statePath -Pid $proc.Id -StdoutLog $stdoutLog -StderrLog $stderrLog -Args $args

    Write-Host "Service gestartet: $($Service.name) (PID $($proc.Id))"
}

$logsDir = Get-LogsDir
$services = @(
    @{ name = 'scan_service'; script = 'SCRIPTS\scan_service_cli.php'; args = @() },
    @{ name = 'forge_service'; script = 'SCRIPTS\forge_service_cli.php'; args = @() },
    @{ name = 'media_service'; script = 'SCRIPTS\media_service_cli.php'; args = @() },
    @{ name = 'library_rename_service'; script = 'SCRIPTS\library_rename_service_cli.php'; args = @() },
    @{ name = 'ollama_service'; script = 'SCRIPTS\ollama_service_cli.php'; args = @() }
)

switch ($Action) {
    'start' {
        foreach ($svc in $services) {
            Reconcile-ServiceState -Service $svc -LogsDir $logsDir
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
            Reconcile-ServiceState -Service $svc -LogsDir $logsDir
            Start-WorkerService -Service $svc -LogsDir $logsDir
        }
    }
    default {
        Write-Host "Unbekannte Action: $Action (unterstützt: start|stop|restart)"
        exit 1
    }
}
