[CmdletBinding()]
param(
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeDir = Join-Path $projectRoot 'tmp\runtime'
$phpDir = Join-Path $runtimeDir 'php-8.3'
$phpExe = Join-Path $phpDir 'php.exe'
$phpIni = Join-Path $phpDir 'php.ini'
$pidFile = Join-Path $runtimeDir 'php-server.pid'
$stdoutLog = Join-Path $runtimeDir 'php-server.log'
$stderrLog = Join-Path $runtimeDir 'php-server.error.log'
$envFile = Join-Path $projectRoot '.env'

if (-not (Test-Path -LiteralPath $phpExe)) {
    throw "PHP runtime not found: $phpExe"
}

if (-not (Test-Path -LiteralPath $phpIni)) {
    throw "PHP configuration not found: $phpIni"
}

if (Test-Path -LiteralPath $envFile) {
    foreach ($line in Get-Content -LiteralPath $envFile) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) {
            continue
        }

        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) {
            continue
        }

        $name = $trimmed.Substring(0, $separator).Trim()
        $value = $trimmed.Substring($separator + 1).Trim()
        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

if (Test-Path -LiteralPath $pidFile) {
    $existingPid = [int](Get-Content -LiteralPath $pidFile -Raw)
    $existing = Get-Process -Id $existingPid -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "Local server is already running (PID $existingPid)."
        Write-Host "http://127.0.0.1:$Port"
        exit 0
    }
    Remove-Item -LiteralPath $pidFile -Force
}

$mysql = Get-Service -Name 'TerraNovaMySQL84' -ErrorAction SilentlyContinue
if (-not $mysql) {
    throw 'MySQL service TerraNovaMySQL84 is not installed.'
}
if ($mysql.Status -ne 'Running') {
    Start-Service -Name 'TerraNovaMySQL84'
}

$arguments = @(
    '-c', 'tmp\runtime\php-8.3\php.ini',
    '-S', "127.0.0.1:$Port",
    '-t', 'public',
    'public\router.php'
)

$process = Start-Process -FilePath $phpExe `
    -ArgumentList $arguments `
    -WorkingDirectory $projectRoot `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdoutLog `
    -RedirectStandardError $stderrLog `
    -PassThru

Set-Content -LiteralPath $pidFile -Value $process.Id
Start-Sleep -Milliseconds 500

if ($process.HasExited) {
    throw "PHP server failed to start. See $stderrLog"
}

Write-Host "Local server started (PID $($process.Id))."
Write-Host "http://127.0.0.1:$Port"
