[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$runtimeDir = Join-Path $projectRoot 'tmp\runtime'
$phpExe = Join-Path $runtimeDir 'php-8.3\php.exe'
$pidFile = Join-Path $runtimeDir 'php-server.pid'

if (-not (Test-Path -LiteralPath $pidFile)) {
    Write-Host 'Local server is not running.'
    exit 0
}

$serverPid = [int](Get-Content -LiteralPath $pidFile -Raw)
$process = Get-Process -Id $serverPid -ErrorAction SilentlyContinue

if ($process) {
    $processPath = $process.Path
    if ($processPath -ne $phpExe) {
        throw "PID $serverPid does not belong to the project PHP runtime."
    }
    Stop-Process -Id $serverPid
}

Remove-Item -LiteralPath $pidFile -Force
Write-Host 'Local server stopped.'
