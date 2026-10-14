param()
$ErrorActionPreference = 'Stop'

$taskRoot = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$taskRuntime = Join-Path $taskRoot '.runtime'
$taskPhp = Join-Path $taskRuntime 'php/php.exe'
$taskIni = Join-Path $taskRuntime 'php/php.ini'
$taskCaDirectory = Join-Path $taskRuntime 'certs'
$taskCaBundle = Join-Path $taskCaDirectory 'cacert.pem'
$taskBundleDownload = Join-Path $taskCaDirectory 'cacert.pem.download'
$taskHashDownload = Join-Path $taskCaDirectory 'cacert.pem.sha256.download'

if (!(Test-Path $taskPhp -PathType Leaf) -or !(Test-Path $taskIni -PathType Leaf)) {
    throw 'Run ops/dev/setup.ps1 first to initialize the project PHP runtime.'
}
New-Item -ItemType Directory -Force $taskCaDirectory | Out-Null

try {
    Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem' -OutFile $taskBundleDownload -ErrorAction Stop
    Invoke-WebRequest -Uri 'https://curl.se/ca/cacert.pem.sha256' -OutFile $taskHashDownload -ErrorAction Stop

    $taskHashText = [System.IO.File]::ReadAllText($taskHashDownload)
    if ($taskHashText -notmatch '(?i)\b([0-9a-f]{64})\b') {
        throw 'Official CA bundle checksum response is invalid.'
    }
    $taskExpectedHash = $Matches[1].ToLowerInvariant()
    $taskActualHash = (Get-FileHash -LiteralPath $taskBundleDownload -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($taskActualHash -ne $taskExpectedHash) {
        throw 'Official CA bundle checksum mismatch.'
    }

    $taskBundleText = [System.IO.File]::ReadAllText($taskBundleDownload)
    if ($taskBundleText -notmatch '-----BEGIN CERTIFICATE-----' -or $taskBundleText -notmatch '-----END CERTIFICATE-----') {
        throw 'Downloaded CA bundle is not a PEM certificate bundle.'
    }

    Move-Item -LiteralPath $taskBundleDownload -Destination $taskCaBundle -Force
    $taskIniLines = [System.Collections.Generic.List[string]]::new()
    $taskIniLines.Add('curl.cainfo = "'+($taskCaBundle -replace '\\', '/')+'"')
    $taskIniLines.Add('openssl.cafile = "'+($taskCaBundle -replace '\\', '/')+'"')
    foreach ($taskLine in [System.IO.File]::ReadAllLines($taskIni, [System.Text.Encoding]::UTF8)) {
        if ($taskLine -notmatch '^\s*(curl\.cainfo|openssl\.cafile)\s*=') {
            $taskIniLines.Add($taskLine)
        }
    }
    [System.IO.File]::WriteAllLines($taskIni, $taskIniLines, [System.Text.UTF8Encoding]::new($false))

    $taskPhpProbe = @'
$bundle = realpath(ini_get('curl.cainfo'));
$opensslBundle = realpath(ini_get('openssl.cafile'));
if ($bundle === false || $bundle !== $opensslBundle || !is_file($bundle)
    || openssl_x509_read(file_get_contents($bundle)) === false) {
    fwrite(STDERR, "PHP CA configuration validation failed.\n");
    exit(1);
}
echo "PHP CA configuration validated.\n";
'@
    $taskProbeOutput = & $taskPhp -r $taskPhpProbe 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw 'PHP could not load the configured CA bundle.'
    }
    if (($taskProbeOutput -join '').Trim() -ne 'PHP CA configuration validated.') {
        throw 'PHP CA configuration validation returned an unexpected result.'
    }

    Write-Output 'Official CA bundle SHA-256 verified; runtime PHP CA settings validated.'
} finally {
    Remove-Item -LiteralPath $taskBundleDownload, $taskHashDownload -Force -ErrorAction SilentlyContinue
}
