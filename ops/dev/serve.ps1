param(
    [ValidateRange(1024, 65535)]
    [int]$Port = 8000,
    [switch]$BrowserTest
)

$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$uploadTemp = Join-Path $taskRoot '.runtime/php-upload-tmp'
$iniDirectory = Join-Path $taskRoot '.runtime/php-server-ini'
$iniPath = Join-Path $iniDirectory 'civora-web.ini'
$templatePath = Join-Path $PSScriptRoot 'php-web.ini.template'
$phpWrapper = Join-Path $PSScriptRoot 'php.ps1'

if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
    throw "Loopback development port $Port is already in use. Inspect its owner before starting Civora."
}

New-Item -ItemType Directory -Force -Path $uploadTemp, $iniDirectory | Out-Null
$iniContents = Get-Content -LiteralPath $templatePath -Raw
$iniContents = $iniContents.Replace('@UPLOAD_TEMP_DIR@', $uploadTemp.Replace('\', '/'))
[System.IO.File]::WriteAllText($iniPath, $iniContents, [System.Text.UTF8Encoding]::new($false))

$previous = @{
    APP_ENV = $env:APP_ENV
    SESSION_DRIVER = $env:SESSION_DRIVER
    PHP_INI_SCAN_DIR = $env:PHP_INI_SCAN_DIR
}
try {
    $env:PHP_INI_SCAN_DIR = $iniDirectory
    if ($BrowserTest) {
        $env:APP_ENV = 'testing'
        # Browser-only convenience: avoid requiring a sessions table in the HTTP server.
        # This does not verify production/database session revocation behavior.
        $env:SESSION_DRIVER = 'file'
    }

    & $phpWrapper artisan serve --host=127.0.0.1 --port=$Port --tries=1 --no-reload
    if ($LASTEXITCODE -ne 0) {
        throw "Civora development server exited with code $LASTEXITCODE."
    }
} finally {
    foreach ($name in $previous.Keys) {
        if ($null -eq $previous[$name]) {
            Remove-Item "Env:$name" -ErrorAction SilentlyContinue
        } else {
            Set-Item "Env:$name" $previous[$name]
        }
    }
}
