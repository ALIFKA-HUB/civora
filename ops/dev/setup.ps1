param([string]$PgBin = 'C:/Program Files/PostgreSQL/18/bin', [int]$Port = 54329, [string]$ComposerPhar = "$env:USERPROFILE/.config/herd-lite/bin/composer.phar")
$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$taskRuntime = Join-Path $taskRoot '.runtime'
$taskPhp = Join-Path $taskRuntime 'php/php.exe'
New-Item -ItemType Directory -Force (Join-Path $taskRuntime 'php') | Out-Null
if (!(Test-Path $taskPhp)) {
    $taskZip = Join-Path $taskRuntime 'php.zip'
    Invoke-WebRequest 'https://downloads.php.net/~windows/releases/archives/php-8.5.11-nts-Win32-vs17-x64.zip' -OutFile $taskZip
    if ((Get-FileHash $taskZip -Algorithm SHA256).Hash.ToLower() -ne '0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043') { throw 'Official PHP archive checksum mismatch.' }
    Expand-Archive $taskZip (Join-Path $taskRuntime 'php') -Force
}
@'
extension_dir="ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_pgsql
extension=pgsql
extension=zip
extension=intl
extension=gd
extension=sodium
memory_limit=512M
date.timezone=Asia/Jakarta
'@ | Set-Content (Join-Path $taskRuntime 'php/php.ini')
$taskComposer = Join-Path $taskRuntime 'composer.phar'
if (!(Test-Path $taskComposer)) {
    Copy-Item $ComposerPhar $taskComposer
    & $taskPhp $taskComposer self-update 2.10.3 --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Local Composer update failed.' }
}
$taskData = Join-Path $taskRuntime 'pgsql'
$taskMarker = Join-Path $taskRuntime 'civora-cluster.json'
$taskPasswordFile = Join-Path $taskRuntime 'pg-password'
if (!(Test-Path $taskMarker)) {
    if (Test-Path $taskData) { throw 'Unmarked existing data directory: refusing to initialize or adopt it.' }
    if ($Port -lt 1024 -or (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) { throw 'Choose an unused high PostgreSQL port.' }
    [Convert]::ToHexString([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32)) | Set-Content $taskPasswordFile
    & "$PgBin/initdb.exe" -D $taskData -U civora -A scram-sha-256 "--pwfile=$taskPasswordFile" --encoding=UTF8 --locale=C
    if ($LASTEXITCODE -ne 0) { throw 'Local PostgreSQL initialization failed.' }
    Add-Content (Join-Path $taskData 'postgresql.conf') "`nlisten_addresses = '127.0.0.1'`nport = $Port`ntimezone = 'UTC'`n"
    @{ data = $taskData; port = $Port } | ConvertTo-Json | Set-Content $taskMarker
}
$taskConfig = Get-Content $taskMarker -Raw | ConvertFrom-Json
& (Join-Path $PSScriptRoot 'postgres.ps1') -Action start -PgBin $PgBin
if ($LASTEXITCODE -ne 0) { throw 'Local PostgreSQL startup failed.' }
$taskPassword = (Get-Content $taskPasswordFile -Raw).Trim()
$env:PGPASSWORD = $taskPassword
try {
    foreach ($taskDatabase in @('civora_dev', 'civora_test')) {
        $taskExists = & "$PgBin/psql.exe" -h 127.0.0.1 -p $taskConfig.port -U civora -d postgres -At -c "SELECT 1 FROM pg_database WHERE datname = '$taskDatabase'"
        if ($LASTEXITCODE -ne 0) { throw 'Local PostgreSQL connection failed.' }
        if ($taskExists -ne '1') {
            & "$PgBin/createdb.exe" -h 127.0.0.1 -p $taskConfig.port -U civora $taskDatabase
            if ($LASTEXITCODE -ne 0) { throw 'Local database creation failed.' }
        }
    }
} finally { Remove-Item Env:/PGPASSWORD -ErrorAction SilentlyContinue }
foreach ($taskFile in @('.env', '.env.testing')) {
    $taskPath = Join-Path $taskRoot "backend/$taskFile"
    if (!(Test-Path $taskPath)) {
        $taskTemplate = Get-Content (Join-Path $taskRoot 'backend/.env.example') -Raw
        $taskKey = 'base64:' + [Convert]::ToBase64String([System.Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
        $taskTemplate = $taskTemplate.Replace('APP_KEY=', "APP_KEY=$taskKey").Replace('DB_PASSWORD=', "DB_PASSWORD=$taskPassword").Replace('DB_PORT=54329', "DB_PORT=$($taskConfig.port)")
        if ($taskFile -eq '.env.testing') { $taskTemplate = $taskTemplate.Replace('APP_ENV=local', 'APP_ENV=testing').Replace('civora_dev', 'civora_test') }
        Set-Content $taskPath $taskTemplate
    }
}
Push-Location (Join-Path $taskRoot 'backend')
try {
    & $taskPhp $taskComposer install --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'Composer install failed.' }
    & $taskPhp artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw 'Development migration failed.' }
} finally { Pop-Location }
Write-Output 'Civora local backend ready. Secrets remain in ignored local files.'
