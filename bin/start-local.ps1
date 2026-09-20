[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker is required.'
}

$envFile = if (Test-Path '.env') { '.env' } elseif (Test-Path '.env.docker') { '.env.docker' } else { throw 'Create .env or .env.docker first.' }

docker compose --env-file $envFile up -d --build
if ($LASTEXITCODE -ne 0) { throw 'docker compose up failed.' }

Write-Host 'COS Symfony runtime started.'
Write-Host 'http://127.0.0.1:8081'
