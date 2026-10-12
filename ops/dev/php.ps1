param([Parameter(ValueFromRemainingArguments = $true)][string[]]$PhpArguments)
$ErrorActionPreference = 'Stop'
$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$taskPhp = Join-Path $taskRoot '.runtime/php/php.exe'
if (!(Test-Path $taskPhp)) { throw 'Run ops/dev/setup.ps1 first.' }
Push-Location (Join-Path $taskRoot 'backend')
try {
    & $taskPhp @PhpArguments
    exit $LASTEXITCODE
} finally { Pop-Location }
