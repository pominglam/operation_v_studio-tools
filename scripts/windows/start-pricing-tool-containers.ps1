# Starts pricing-tool Docker Compose stack after Docker Desktop is ready.
# Registered at user logon by install-pc-startup.ps1

$ErrorActionPreference = 'Stop'

$ProjectRoot = 'C:\Users\plam\workspace\operation-v\pricing-tool'
$LogDir = Join-Path $env:LOCALAPPDATA 'operation-v\logs'
$LogFile = Join-Path $LogDir 'start-pricing-tool-containers.log'

function Write-Log([string]$Message) {
    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
    Add-Content -Path $LogFile -Value $line
}

try {
    Write-Log 'Waiting for Docker daemon...'

    $dockerReady = $false
    foreach ($attempt in 1..36) {
        & docker info *> $null
        if ($LASTEXITCODE -eq 0) {
            $dockerReady = $true
            break
        }
        Start-Sleep -Seconds 10
    }

    if (-not $dockerReady) {
        Write-Log 'Docker daemon not ready after 6 minutes; exiting.'
        exit 1
    }

    Write-Log "Running docker compose up -d in $ProjectRoot"
    Set-Location $ProjectRoot
    & docker compose up -d 2>&1 | ForEach-Object { Write-Log $_ }

    if ($LASTEXITCODE -ne 0) {
        Write-Log "docker compose failed with exit code $LASTEXITCODE"
        exit $LASTEXITCODE
    }

    Write-Log 'Containers started successfully.'
    exit 0
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
