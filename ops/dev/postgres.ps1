param([ValidateSet('start', 'stop', 'status')][string]$Action = 'status', [string]$PgBin = 'C:/Program Files/PostgreSQL/18/bin')
$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$taskData = Join-Path $taskRoot '.runtime/pgsql'
$taskMarker = Join-Path $taskRoot '.runtime/civora-cluster.json'
if (!(Test-Path $taskMarker)) { throw 'Civora cluster marker missing. Run setup.ps1 first.' }
$taskConfig = Get-Content $taskMarker -Raw | ConvertFrom-Json
if ($taskConfig.data -ne $taskData -or $taskConfig.port -lt 1024) { throw 'Invalid local cluster configuration.' }
switch ($Action) {
    'status' { & "$PgBin/pg_ctl.exe" status -D $taskData; exit $LASTEXITCODE }
    'stop' { & "$PgBin/pg_ctl.exe" stop -D $taskData -m fast -w; exit $LASTEXITCODE }
    'start' {
        & "$PgBin/pg_ctl.exe" status -D $taskData *> $null
        if ($LASTEXITCODE -eq 0) { Write-Output 'Civora PostgreSQL already running.'; exit 0 }
        $taskListener = Get-NetTCPConnection -LocalPort $taskConfig.port -State Listen -ErrorAction SilentlyContinue
        if ($taskListener) { throw 'Civora PostgreSQL port is occupied by another process.' }
        # Launch hidden; polling avoids pg_ctl child handles blocking Start-Process -Wait on Windows.
        Start-Process "$PgBin/postgres.exe" -ArgumentList @('-D', ('"' + $taskData + '"')) -WindowStyle Hidden -RedirectStandardOutput (Join-Path $taskRoot '.runtime/postgres.stdout.log') -RedirectStandardError (Join-Path $taskRoot '.runtime/postgres.stderr.log') | Out-Null
        for ($taskAttempt = 0; $taskAttempt -lt 40; $taskAttempt++) {
            & "$PgBin/pg_isready.exe" -h 127.0.0.1 -p $taskConfig.port *> $null
            if ($LASTEXITCODE -eq 0) { Write-Output 'Civora PostgreSQL ready.'; exit 0 }
            Start-Sleep -Milliseconds 250
        }
        throw 'Civora PostgreSQL did not become ready; inspect ignored runtime logs.'
    }
}
