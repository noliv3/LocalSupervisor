param(
    [string]$Action = '',
    [switch]$UpdateNow,
    [int]$FetchIntervalHours = 3,
    [int]$BackupKeep = 8
)

$action = ''
if ($UpdateNow.IsPresent) {
    $action = 'update_ff_restart'
} elseif (-not [string]::IsNullOrWhiteSpace($Action)) {
    $action = $Action.Trim()
}

$scriptRoot = $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($scriptRoot)) {
    $scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
}

$base = $env:SV_BASE
if ([string]::IsNullOrWhiteSpace($base)) {
    $base = $scriptRoot
}
if (-not (Test-Path -Path $base)) {
    Write-Host "Basisverzeichnis nicht gefunden: $base"
    exit 1
}
$base = (Resolve-Path -Path $base).Path
$script:SupervisorPid = [System.Diagnostics.Process]::GetCurrentProcess().Id

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

Set-Location $base

function Sanitize-Message {
    param([string]$Message)
    if ([string]::IsNullOrWhiteSpace($Message)) {
        return ''
    }
    $value = $Message -replace '\s+', ' '
    $value = $value -replace '(?i)\b(?:mysql|pgsql|sqlite|sqlsrv):[^\s''`"]+', '<dsn>'
    $value = $value -replace '(?i)\b(api[_-]?key|token|secret|password|pass)\s*[:=]\s*[^\s''`",;]+', '$1=<redacted>'
    $value = $value -replace '(?:(?:[A-Za-z]:)?[\\/](?:[^\s''`"<>]+))+', '[path]'
    if ($value.Length -gt 200) {
        $value = $value.Substring(0, 200)
    }
    return $value
}

function Get-CommandOutput {
    param($Output)
    if ($null -eq $Output) {
        return ''
    }
    if ($Output -is [string]) {
        return $Output.Trim()
    }
    return ($Output | Out-String).Trim()
}

function Get-ConfigPath {
    param(
        [string]$Expr,
        [string]$Fallback
    )

    $value = & $phpExe @phpArgs -r "require 'SCRIPTS/common.php'; \$cfg = sv_load_config(); \$val = {$Expr}; if (is_string(\$val) && \$val !== '') { echo \$val; }" 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($value)) {
        return $Fallback
    }

    return $value.Trim()
}

function Get-LogDir {
    $fallback = Join-Path $base 'LOGS'
    $value = Get-ConfigPath "\$cfg['paths']['logs'] ?? (sv_base_dir() . '/LOGS')" $fallback
    if (-not (Test-Path -Path $value)) {
        New-Item -ItemType Directory -Force -Path $value | Out-Null
    }
    return $value
}

function Get-BackupDir {
    $fallback = Join-Path $base 'BACKUPS'
    $value = Get-ConfigPath "\$cfg['paths']['backups'] ?? (sv_base_dir() . '/BACKUPS')" $fallback
    if (-not (Test-Path -Path $value)) {
        New-Item -ItemType Directory -Force -Path $value | Out-Null
    }
    return $value
}

function Write-JsonFile {
    param(
        [string]$Path,
        [hashtable]$Payload
    )
    $json = $Payload | ConvertTo-Json -Depth 6
    $dir = Split-Path -Path $Path -Parent
    if (-not (Test-Path -Path $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    Set-Content -Path $Path -Value $json -Encoding UTF8
}

function Write-StartLog {
    param([string]$Message)
    if ([string]::IsNullOrWhiteSpace($Message)) {
        return
    }
    $stamp = (Get-Date).ToString('o')
    $line = "[$stamp] $Message"
    Write-Host $line
    if (-not [string]::IsNullOrWhiteSpace($script:StartLogPath)) {
        try {
            Add-Content -Path $script:StartLogPath -Value $line -Encoding UTF8
        } catch {
        }
    }
}

function Test-ProcessAlive {
    param([int]$ProcessId)
    if ($ProcessId -le 0) {
        return $false
    }
    try {
        Get-Process -Id $ProcessId -ErrorAction Stop | Out-Null
        return $true
    } catch {
        return $false
    }
}

function Acquire-Lock {
    param(
        [string]$Path,
        [string]$Label
    )
    if (Test-Path -Path $Path) {
        $info = $null
        try {
            $raw = Get-Content -Path $Path -Raw -ErrorAction SilentlyContinue
            if (-not [string]::IsNullOrWhiteSpace($raw)) {
                $info = $raw | ConvertFrom-Json
            }
        } catch {
            $info = $null
        }
        $existingProcessId = 0
        if ($null -ne $info -and $null -ne $info.pid) {
            $existingProcessId = [int]$info.pid
        }
        if ($existingProcessId -gt 0 -and (Test-ProcessAlive -ProcessId $existingProcessId)) {
            Write-StartLog "Lock aktiv ($Label), PID $existingProcessId."
            return $false
        }
        try {
            Remove-Item -Path $Path -Force -ErrorAction SilentlyContinue
        } catch {
        }
    }

    $payload = [ordered]@{
        pid        = $script:SupervisorPid
        action     = $Label
        started_at = (Get-Date).ToString('o')
    }
    Write-JsonFile -Path $Path -Payload $payload
    return $true
}

function Release-Lock {
    param([string]$Path)
    if (Test-Path -Path $Path) {
        try {
            Remove-Item -Path $Path -Force -ErrorAction SilentlyContinue
        } catch {
        }
    }
}

function Test-PhpServerPid {
    param([int]$PhpPid)

    if ($PhpPid -le 0) { return $false }

    try {
        $proc = Get-Process -Id $PhpPid -ErrorAction Stop
    } catch {
        return $false
    }

    return ($proc.ProcessName -ieq 'php')
}

function Invoke-HealthCheck {
    param(
        [string]$Url,
        [int]$Attempts = 6,
        [int]$DelaySeconds = 1
    )
    for ($i = 0; $i -lt $Attempts; $i++) {
        try {
            $resp = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 4
            if ($resp -and $resp.StatusCode -eq 200) {
                return $true
            }
        } catch {
        }
        Start-Sleep -Seconds $DelaySeconds
    }
    return $false
}

function Get-DatabasePath {
    $dsn = & $phpExe @phpArgs -r "require 'SCRIPTS/common.php'; \$cfg = sv_load_config(); echo (string)(\$cfg['db']['dsn'] ?? '');" 2>$null
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($dsn)) {
        return $null
    }
    $dsn = $dsn.Trim()
    if (-not $dsn.StartsWith('sqlite:')) {
        return $null
    }
    $dbPath = $dsn.Substring(7)
    if ([string]::IsNullOrWhiteSpace($dbPath)) {
        return $null
    }
    $isAbsolute = $dbPath -match '^(?:[A-Za-z]:\\|\\\\|/)'
    if (-not $isAbsolute) {
        $dbPath = Join-Path $base $dbPath
    }
    $resolved = Resolve-Path -Path $dbPath -ErrorAction SilentlyContinue
    if ($resolved) {
        return $resolved.Path
    }
    return $dbPath
}

function Get-GitStatus {
    $branch = Get-CommandOutput (& git rev-parse --abbrev-ref HEAD 2>$null)
    $head = Get-CommandOutput (& git rev-parse HEAD 2>$null)
    $dirtyOutput = & git status --porcelain 2>$null
    $dirtyLines = @()
    $dirtyText = Get-CommandOutput $dirtyOutput
    if (-not [string]::IsNullOrWhiteSpace($dirtyText)) {
        $dirtyLines = $dirtyText -split "`r?`n" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
    }
    $dirty = $dirtyLines.Count -gt 0

    $upstream = Get-CommandOutput (& git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>$null)
    if ($LASTEXITCODE -ne 0) {
        $upstream = ''
    }

    $ahead = $null
    $behind = $null
    $countsOk = $false
    if (-not [string]::IsNullOrWhiteSpace($upstream)) {
        $counts = Get-CommandOutput (& git rev-list --left-right --count HEAD...$upstream 2>$null)
        if ($counts -match '^(\d+)\s+(\d+)$') {
            $ahead = [int]$Matches[1]
            $behind = [int]$Matches[2]
            $countsOk = $true
        }
    }

    return [ordered]@{
        updated_at  = (Get-Date).ToString('o')
        branch      = $branch
        head        = $head
        upstream    = $upstream
        ahead       = $ahead
        behind      = $behind
        counts_ok   = $countsOk
        dirty       = $dirty
        dirty_count = $dirtyLines.Count
    }
}

function Update-GitStatus {
    $logDir = Get-LogDir
    $fetchOutput = & git fetch 2>&1
    $fetchOk = $LASTEXITCODE -eq 0
    $fetchError = ''
    if (-not $fetchOk) {
        $fetchError = Sanitize-Message (Get-CommandOutput $fetchOutput)
        Write-StartLog "Git fetch fehlgeschlagen: $fetchError"
    }

    $status = Get-GitStatus
    $status['fetch_ok'] = $fetchOk
    if ($fetchError -ne '') {
        $status['fetch_error'] = $fetchError
    }

    $statusPath = Join-Path $logDir 'git_status.json'
    Write-JsonFile -Path $statusPath -Payload $status

    return $status
}

function Rotate-Backups {
    param(
        [string]$BackupDir,
        [int]$Keep
    )
    if ($Keep -le 0) {
        return
    }

    $items = Get-ChildItem -Path $BackupDir -File -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -match '^supervisor_\d{8}_\d{6}'
    }

    $groups = @{}
    foreach ($item in $items) {
        if ($item.Name -match '^supervisor_(\d{8}_\d{6})') {
            $stamp = $Matches[1]
            if (-not $groups.ContainsKey($stamp)) {
                $groups[$stamp] = @()
            }
            $groups[$stamp] += $item
        }
    }

    $sortedKeys = $groups.Keys | Sort-Object -Descending
    $dropKeys = $sortedKeys | Select-Object -Skip $Keep
    foreach ($key in $dropKeys) {
        foreach ($item in $groups[$key]) {
            try {
                Remove-Item -Path $item.FullName -Force -ErrorAction SilentlyContinue
            } catch {
            }
        }
    }
}

function Stop-PhpServer {
    param([string]$Reason)

    $logDir = Get-LogDir
    $pidPath = Join-Path $logDir 'php_server.pid'

    if (-not (Test-Path -Path $pidPath)) {
        return
    }

    $pidValue = Get-Content -Path $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not ($pidValue -match '^\d+$')) {
        return
    }

    $phpPid = [int]$pidValue
    if (-not (Test-PhpServerPid -PhpPid $phpPid)) {
        try { Remove-Item -Path $pidPath -Force -ErrorAction SilentlyContinue } catch {}
        return
    }

    try {
        Write-StartLog "Stoppe PHP-Server (PID $phpPid) wegen: $Reason"
        Stop-Process -Id $phpPid -Force -ErrorAction SilentlyContinue
    } catch {
    }

    try { Remove-Item -Path $pidPath -Force -ErrorAction SilentlyContinue } catch {}
}

function Start-PhpServer {
    param([string]$Reason)
    $logDir = Get-LogDir
    $pidPath = Join-Path $logDir 'php_server.pid'
    $outLog = Join-Path $logDir 'php_server.out.log'
    $errLog = Join-Path $logDir 'php_server.err.log'

    $serverArgs = @()
    if ($phpArgs.Count -gt 0) {
        $serverArgs += $phpArgs
    }
    $serverArgs += @("-S", "0.0.0.0:8080", "-t", "WWW")
    Write-StartLog "Starte PHP-Server ($Reason)."
    $proc = Start-Process -FilePath $phpExe -ArgumentList $serverArgs -RedirectStandardOutput $outLog -RedirectStandardError $errLog -PassThru
    if ($null -ne $proc) {
        Set-Content -Path $pidPath -Value $proc.Id -Encoding UTF8
    }
    return $proc
}

function Restart-PhpServer {
    param([string]$Reason)

    Stop-PhpServer -Reason "restart:$Reason"

    $proc = Start-PhpServer -Reason $Reason
    if ($null -eq $proc) {
        return $false
    }

    $healthOk = Invoke-HealthCheck -Url 'http://127.0.0.1:8080/health.php'
    if (-not $healthOk) {
        Write-StartLog 'Healthcheck fehlgeschlagen, stoppe PHP-Server.'
        try {
            Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue
        } catch {
        }
        Stop-PhpServer -Reason 'healthcheck_failed'
        return $false
    }

    Write-StartLog 'Healthcheck erfolgreich.'
    return $true
}

function Should-AutoUpdate {
    param([hashtable]$Status)

    if ($null -eq $Status) { return $false }
    if (-not $Status['fetch_ok']) { return $false }
    if ([string]::IsNullOrWhiteSpace([string]$Status['upstream'])) { return $false }
    if ($Status['dirty']) { return $false }
    if (-not $Status['counts_ok']) { return $false }
    if ($null -eq $Status['behind']) { return $false }
    $behind = [int]$Status['behind']
    $ahead = 0
    if ($null -ne $Status['ahead']) {
        $ahead = [int]$Status['ahead']
    }
    if ($behind -le 0) { return $false }
    if ($ahead -gt 0 -and $behind -gt 0) { return $false }
    return ($ahead -eq 0)
}

function Invoke-UpdateFlow {
    param(
        [string]$Mode,
        [hashtable]$BeforeStatus
    )

    $script:Updating = $true
    try {
        $status = $BeforeStatus
        if ($null -eq $status) {
            $status = Update-GitStatus
        }

        $result = [ordered]@{
            ok = $false
            short_error = ''
            steps = [ordered]@{}
            before = [ordered]@{
                commit = $status['head']
                branch = $status['branch']
                ahead  = $status['ahead']
                behind = $status['behind']
                dirty  = $status['dirty']
            }
            after = $null
        }

        $dirtyOutput = & git status --porcelain 2>$null
        $dirtyText = Get-CommandOutput $dirtyOutput
        if (-not [string]::IsNullOrWhiteSpace($dirtyText)) {
            $result['short_error'] = 'Working tree dirty.'
            return $result
        }

        $upstream = $status['upstream']
        if ([string]::IsNullOrWhiteSpace($upstream)) {
            $result['short_error'] = 'Kein Upstream-Branch gesetzt.'
            return $result
        }

        $steps = [ordered]@{}

        if ($Mode -eq 'update_ff_restart') {
            $ahead = $status['ahead']
            $behind = $status['behind']
            if ($behind -eq $null) {
                $result['short_error'] = 'Git-Status unvollständig.'
                return $result
            }
            if ($ahead -gt 0 -and $behind -gt 0) {
                $result['short_error'] = 'Branch divergiert, FF-Pull nicht möglich.'
                return $result
            }
            if ($behind -gt 0) {
                $pullOutput = & git pull --ff-only 2>&1
                if ($LASTEXITCODE -ne 0) {
                    $result['short_error'] = Sanitize-Message (Get-CommandOutput $pullOutput)
                    Write-StartLog "Git Pull fehlgeschlagen, rollback auf $($status['head'])."
                    & git reset --hard $status['head'] 2>$null
                    return $result
                }
                $steps['pull'] = 'ff'
            } else {
                $steps['pull'] = 'noop'
            }
        } else {
            $pullOutput = & git pull --no-edit 2>&1
            if ($LASTEXITCODE -ne 0) {
                $result['short_error'] = Sanitize-Message (Get-CommandOutput $pullOutput)
                Write-StartLog "Git Pull fehlgeschlagen, rollback auf $($status['head'])."
                & git reset --hard $status['head'] 2>$null
                return $result
            }
            $conflicts = Get-CommandOutput (& git diff --name-only --diff-filter=U 2>$null)
            if (-not [string]::IsNullOrWhiteSpace($conflicts)) {
                & git merge --abort 2>$null
                $result['short_error'] = 'Merge-Konflikt erkannt, Abbruch.'
                & git reset --hard $status['head'] 2>$null
                return $result
            }
            $steps['pull'] = 'merge'
        }

        $backupOutput = & $phpExe @phpArgs "SCRIPTS\db_backup.php" 2>&1
        if ($LASTEXITCODE -ne 0) {
            $result['short_error'] = Sanitize-Message (Get-CommandOutput $backupOutput)
            $steps['backup'] = 'error'
            $result['steps'] = $steps
            return $result
        }
        $steps['backup'] = 'ok'

        $backupDir = Get-BackupDir
        Rotate-Backups -BackupDir $backupDir -Keep $BackupKeep
        $steps['backup_rotate'] = "keep=$BackupKeep"

        $migrateOutput = & $phpExe @phpArgs "SCRIPTS\migrate.php" 2>&1
        if ($LASTEXITCODE -ne 0) {
            $result['short_error'] = Sanitize-Message (Get-CommandOutput $migrateOutput)
            $steps['migrate'] = 'error'
            $result['steps'] = $steps
            Write-StartLog "Migration fehlgeschlagen, starte Rollback."
            $dbPath = Get-DatabasePath
            if ($dbPath) {
                $latestBackup = Get-ChildItem -Path $backupDir -File -Filter 'supervisor_*.sqlite' -ErrorAction SilentlyContinue |
                    Sort-Object LastWriteTime -Descending | Select-Object -First 1
                if ($latestBackup) {
                    try {
                        Copy-Item -Path $latestBackup.FullName -Destination $dbPath -Force
                        Write-StartLog "DB-Backup wiederhergestellt: $($latestBackup.Name)"
                    } catch {
                        Write-StartLog "DB-Restore fehlgeschlagen: $($_.Exception.Message)"
                    }
                }
            }
            & git reset --hard $status['head'] 2>$null
            return $result
        }
        $steps['migrate'] = 'ok'

        $restartOk = Restart-PhpServer -Reason 'update'
        if ($restartOk) {
            $steps['restart'] = 'php'
        } else {
            $steps['restart'] = 'php_failed'
        }
        if (-not $restartOk) {
            $result['short_error'] = 'Healthcheck fehlgeschlagen.'
            $result['steps'] = $steps
            return $result
        }

        $afterStatus = Update-GitStatus
        $result['after'] = [ordered]@{
            commit = $afterStatus['head']
            branch = $afterStatus['branch']
            ahead  = $afterStatus['ahead']
            behind = $afterStatus['behind']
            dirty  = $afterStatus['dirty']
        }
        $result['steps'] = $steps
        $result['ok'] = $true
        return $result
    } finally {
        $script:Updating = $false
    }
}

$logDir = Get-LogDir
$script:StartLogPath = Join-Path $logDir 'start.log'
$script:Updating = $false
$startLockPath = Join-Path $logDir 'start.lock'
$updateLockPath = Join-Path $logDir 'update.lock'

$allowedUpdateActions = @('update_ff_restart', 'merge_restart')
$isUpdateAction = $action -ne '' -and ($allowedUpdateActions -contains $action)
if (-not $isUpdateAction) {
    if (-not (Acquire-Lock -Path $startLockPath -Label 'start')) {
        Write-Host "Start bereits aktiv (Lock: $startLockPath)."
        exit 1
    }
}

$exitHookRegistered = $false
try {
    Register-EngineEvent -SourceIdentifier PowerShell.Exiting -Action {
        try {
            $base = $env:SV_BASE
            if ([string]::IsNullOrWhiteSpace($base)) {
                $base = (Get-Location).Path
            }
            $logDir = Join-Path $base 'LOGS'
            $pidPath = Join-Path $logDir 'php_server.pid'
            if (Test-Path -Path $pidPath) {
                $pidValue = Get-Content -Path $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($pidValue -match '^\d+$') {
                    $pid = [int]$pidValue
                    try { Stop-Process -Id $pid -Force -ErrorAction SilentlyContinue } catch {}
                }
                try { Remove-Item -Path $pidPath -Force -ErrorAction SilentlyContinue } catch {}
            }
        } catch {
        }
    } | Out-Null
    $exitHookRegistered = $true
} catch {
}

if ($action -ne '') {
    if (-not $isUpdateAction) {
        Release-Lock -Path $startLockPath
        Write-Host "Unbekannte Aktion: $action"
        exit 1
    }

    if (-not (Acquire-Lock -Path $updateLockPath -Label $action)) {
        $lastPath = Join-Path $logDir 'git_update.last.json'
        $payload = [ordered]@{
            action = $action
            started_at = (Get-Date).ToString('o')
            result = 'error'
            reason = 'update_lock_busy'
            finished_at = (Get-Date).ToString('o')
        }
        Write-JsonFile -Path $lastPath -Payload $payload
        Write-Host "Update bereits aktiv (Lock: $updateLockPath)."
        exit 1
    }

    try {
        $lastPath = Join-Path $logDir 'git_update.last.json'
        $startedAt = (Get-Date).ToString('o')
        $beforeStatus = Update-GitStatus
        $flowResult = Invoke-UpdateFlow -Mode $action -BeforeStatus $beforeStatus
        $payload = [ordered]@{
            action = $action
            started_at = $startedAt
            result = 'error'
            before = $flowResult['before']
            steps = $flowResult['steps']
            finished_at = (Get-Date).ToString('o')
        }
        if (-not [string]::IsNullOrWhiteSpace($flowResult['short_error'])) {
            $payload['short_error'] = $flowResult['short_error']
        }
        if ($flowResult['after']) {
            $payload['after'] = $flowResult['after']
        }
        if ($flowResult['ok']) {
            $payload['result'] = 'ok'
        }
        Write-JsonFile -Path $lastPath -Payload $payload
        if (-not $flowResult['ok']) {
            exit 1
        }
        exit 0
    } finally {
        Release-Lock -Path $updateLockPath
    }
}

try {
    Write-StartLog 'Starte SuperVisOr (DB-Init + PHP-Server)...'

    $initOutput = & $phpExe @phpArgs "SCRIPTS\init_db.php" 2>&1
    if ($LASTEXITCODE -ne 0) {
        $initMessage = Sanitize-Message (Get-CommandOutput $initOutput)
        Write-StartLog "DB-Init fehlgeschlagen: $initMessage"
        exit 1
    }

    $serverOk = Restart-PhpServer -Reason 'startup'
    if (-not $serverOk) {
        Write-StartLog 'PHP-Server konnte nicht gestartet werden.'
        exit 1
    }

    $pidPath = Join-Path $logDir 'php_server.pid'
    $nextFetch = Get-Date
    while ($true) {
        $now = Get-Date
        if ($now -ge $nextFetch) {
            $status = Update-GitStatus
            if (Should-AutoUpdate -Status $status) {
                Write-StartLog "Auto-Update erkannt: behind=$($status['behind'])"
                if (Acquire-Lock -Path $updateLockPath -Label 'auto_ff_restart') {
                    try {
                        Write-StartLog 'Auto-Update gestartet'
                        $startedAt = (Get-Date).ToString('o')
                        $flowResult = Invoke-UpdateFlow -Mode 'update_ff_restart' -BeforeStatus $status
                        $lastPath = Join-Path $logDir 'git_update.last.json'
                        $payload = [ordered]@{
                            action = 'auto_ff_restart'
                            started_at = $startedAt
                            result = 'error'
                            before = $flowResult['before']
                            steps = $flowResult['steps']
                            finished_at = (Get-Date).ToString('o')
                        }
                        if (-not [string]::IsNullOrWhiteSpace($flowResult['short_error'])) {
                            $payload['short_error'] = $flowResult['short_error']
                        }
                        if ($flowResult['after']) {
                            $payload['after'] = $flowResult['after']
                        }
                        if ($flowResult['ok']) {
                            $payload['result'] = 'ok'
                        }
                        Write-JsonFile -Path $lastPath -Payload $payload
                        if ($flowResult['ok']) {
                            Write-StartLog "Auto-Update OK: $($flowResult['after']['commit'])"
                        } else {
                            Write-StartLog "Auto-Update fehlgeschlagen: $($flowResult['short_error'])"
                        }
                    } finally {
                        Release-Lock -Path $updateLockPath
                    }
                }
            }
            $nextFetch = $now.AddHours([double]$FetchIntervalHours)
        }

        if ($script:Updating) {
            Start-Sleep -Seconds 5
            continue
        }

        $currentProcessId = 0
        if (Test-Path -Path $pidPath) {
            $pidValue = Get-Content -Path $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
            if ($pidValue -match '^\d+$') {
                $currentProcessId = [int]$pidValue
            }
        }

        if ($currentProcessId -le 0 -or -not (Test-PhpServerPid -PhpPid $currentProcessId)) {
            Write-StartLog 'PHP-Server nicht aktiv, Neustart durch Watchdog.'
            Restart-PhpServer -Reason 'watchdog' | Out-Null
        }

        Start-Sleep -Seconds 5
    }
} finally {
    try {
        Stop-PhpServer -Reason 'supervisor_exit'
    } catch {
    }

    try {
        if ($exitHookRegistered) {
            Unregister-Event -SourceIdentifier PowerShell.Exiting -ErrorAction SilentlyContinue
        }
    } catch {
    }

    Release-Lock -Path $startLockPath
}
