param(
    [string]$Action = '',
    [switch]$UpdateNow,
    [int]$FetchIntervalHours = 3,
    [int]$BackupKeep = 8
)

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
    $value = $value -replace '(?i)\b(?:mysql|pgsql|sqlite|sqlsrv):[^\s\'\"]+', '<dsn>'
    $value = $value -replace '(?i)\b(api[_-]?key|token|secret|password|pass)\s*[:=]\s*[^\s\'\",;]+', '$1=<redacted>'
    $value = $value -replace '(?:(?:[A-Za-z]:)?[\\/](?:[^\s\'"<>]+))+', '[path]'
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
    if (-not [string]::IsNullOrWhiteSpace($upstream)) {
        $counts = Get-CommandOutput (& git rev-list --left-right --count HEAD...$upstream 2>$null)
        if ($counts -match '^(\d+)\s+(\d+)$') {
            $ahead = [int]$Matches[1]
            $behind = [int]$Matches[2]
        }
    }

    return [ordered]@{
        updated_at  = (Get-Date).ToString('o')
        branch      = $branch
        head        = $head
        upstream    = $upstream
        ahead       = $ahead
        behind      = $behind
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
                # Ignore cleanup errors.
            }
        }
    }
}

function Restart-PhpServer {
    $logDir = Get-LogDir
    $pidPath = Join-Path $logDir 'php_server.pid'
    if (Test-Path -Path $pidPath) {
        $pidValue = Get-Content -Path $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($pidValue -match '^\d+$') {
            try {
                Stop-Process -Id ([int]$pidValue) -Force -ErrorAction SilentlyContinue
            } catch {
                # Ignore stop errors.
            }
        }
    }

    $serverArgs = @()
    if ($phpArgs.Count -gt 0) {
        $serverArgs += $phpArgs
    }
    $serverArgs += @("-S", "127.0.0.1:8080", "-t", "WWW")
    $proc = Start-Process -FilePath $phpExe -ArgumentList $serverArgs -PassThru
    if ($null -ne $proc) {
        Set-Content -Path $pidPath -Value $proc.Id -Encoding UTF8
    }
}

$action = ''
if ($UpdateNow.IsPresent) {
    $action = 'update_ff_restart'
} elseif (-not [string]::IsNullOrWhiteSpace($Action)) {
    $action = $Action.Trim()
}

if ($action -ne '') {
    $allowedActions = @('update_ff_restart', 'merge_restart')
    if (-not ($allowedActions -contains $action)) {
        Write-Host "Unbekannte Aktion: $action"
        exit 1
    }

    $logDir = Get-LogDir
    $lastPath = Join-Path $logDir 'git_update.last.json'
    $startedAt = (Get-Date).ToString('o')
    $beforeStatus = Update-GitStatus
    $result = [ordered]@{
        action = $action
        started_at = $startedAt
        result = 'error'
        before = [ordered]@{
            commit = $beforeStatus['head']
            branch = $beforeStatus['branch']
            ahead  = $beforeStatus['ahead']
            behind = $beforeStatus['behind']
            dirty  = $beforeStatus['dirty']
        }
    }

    $dirtyOutput = & git status --porcelain 2>$null
    $dirtyText = Get-CommandOutput $dirtyOutput
    if (-not [string]::IsNullOrWhiteSpace($dirtyText)) {
        $result['short_error'] = 'Working tree dirty.'
        $result['finished_at'] = (Get-Date).ToString('o')
        Write-JsonFile -Path $lastPath -Payload $result
        exit 1
    }

    $upstream = $beforeStatus['upstream']
    if ([string]::IsNullOrWhiteSpace($upstream)) {
        $result['short_error'] = 'Kein Upstream-Branch gesetzt.'
        $result['finished_at'] = (Get-Date).ToString('o')
        Write-JsonFile -Path $lastPath -Payload $result
        exit 1
    }

    $steps = [ordered]@{}

    if ($action -eq 'update_ff_restart') {
        $ahead = $beforeStatus['ahead']
        $behind = $beforeStatus['behind']
        if ($behind -eq $null) {
            $result['short_error'] = 'Git-Status unvollständig.'
            $result['finished_at'] = (Get-Date).ToString('o')
            Write-JsonFile -Path $lastPath -Payload $result
            exit 1
        }
        if ($ahead -gt 0 -and $behind -gt 0) {
            $result['short_error'] = 'Branch divergiert, FF-Pull nicht möglich.'
            $result['finished_at'] = (Get-Date).ToString('o')
            Write-JsonFile -Path $lastPath -Payload $result
            exit 1
        }
        if ($behind -gt 0) {
            $pullOutput = & git pull --ff-only 2>&1
            if ($LASTEXITCODE -ne 0) {
                $result['short_error'] = Sanitize-Message (Get-CommandOutput $pullOutput)
                $result['finished_at'] = (Get-Date).ToString('o')
                Write-JsonFile -Path $lastPath -Payload $result
                exit 1
            }
            $steps['pull'] = 'ff'
        } else {
            $steps['pull'] = 'noop'
        }
    } else {
        $pullOutput = & git pull --no-edit 2>&1
        if ($LASTEXITCODE -ne 0) {
            $result['short_error'] = Sanitize-Message (Get-CommandOutput $pullOutput)
            $result['finished_at'] = (Get-Date).ToString('o')
            Write-JsonFile -Path $lastPath -Payload $result
            exit 1
        }
        $conflicts = Get-CommandOutput (& git diff --name-only --diff-filter=U 2>$null)
        if (-not [string]::IsNullOrWhiteSpace($conflicts)) {
            & git merge --abort 2>$null
            $result['short_error'] = 'Merge-Konflikt erkannt, Abbruch.'
            $result['finished_at'] = (Get-Date).ToString('o')
            Write-JsonFile -Path $lastPath -Payload $result
            exit 1
        }
        $steps['pull'] = 'merge'
    }

    $backupOutput = & $phpExe @phpArgs "SCRIPTS\db_backup.php" 2>&1
    if ($LASTEXITCODE -ne 0) {
        $result['short_error'] = Sanitize-Message (Get-CommandOutput $backupOutput)
        $result['finished_at'] = (Get-Date).ToString('o')
        $steps['backup'] = 'error'
        $result['steps'] = $steps
        Write-JsonFile -Path $lastPath -Payload $result
        exit 1
    }
    $steps['backup'] = 'ok'

    $backupDir = Get-BackupDir
    Rotate-Backups -BackupDir $backupDir -Keep $BackupKeep
    $steps['backup_rotate'] = "keep=$BackupKeep"

    $migrateOutput = & $phpExe @phpArgs "SCRIPTS\migrate.php" 2>&1
    if ($LASTEXITCODE -ne 0) {
        $result['short_error'] = Sanitize-Message (Get-CommandOutput $migrateOutput)
        $result['finished_at'] = (Get-Date).ToString('o')
        $steps['migrate'] = 'error'
        $result['steps'] = $steps
        Write-JsonFile -Path $lastPath -Payload $result
        exit 1
    }
    $steps['migrate'] = 'ok'

    Restart-PhpServer
    $steps['restart'] = 'php'

    $afterStatus = Update-GitStatus
    $result['after'] = [ordered]@{
        commit = $afterStatus['head']
        branch = $afterStatus['branch']
        ahead  = $afterStatus['ahead']
        behind = $afterStatus['behind']
        dirty  = $afterStatus['dirty']
    }
    $result['steps'] = $steps
    $result['result'] = 'ok'
    $result['finished_at'] = (Get-Date).ToString('o')
    Write-JsonFile -Path $lastPath -Payload $result
    exit 0
}

Write-Host "Starte SuperVisOr (DB-Init + PHP-Server)..."

& $phpExe @phpArgs "SCRIPTS\init_db.php"

$logDir = Get-LogDir
$pidPath = Join-Path $logDir 'php_server.pid'
$serverArgs = @()
if ($phpArgs.Count -gt 0) {
    $serverArgs += $phpArgs
}
$serverArgs += @("-S", "127.0.0.1:8080", "-t", "WWW")
$phpProc = Start-Process -FilePath $phpExe -ArgumentList $serverArgs -PassThru
if ($null -ne $phpProc) {
    Set-Content -Path $pidPath -Value $phpProc.Id -Encoding UTF8
}

$nextFetch = Get-Date
while ($true) {
    $now = Get-Date
    if ($now -ge $nextFetch) {
        Update-GitStatus | Out-Null
        $nextFetch = $now.AddHours([double]$FetchIntervalHours)
    }
    Start-Sleep -Seconds 10
}
