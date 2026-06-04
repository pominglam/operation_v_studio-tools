# Starts the named Cloudflare tunnel (ovs.centredentairevsl.com) in the user profile.
# Registered at user logon by install-pc-startup.ps1

$ErrorActionPreference = 'Stop'

$CloudflaredExe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$ConfigPath = Join-Path $env:USERPROFILE '.cloudflared\config.yml'
$LogDir = Join-Path $env:LOCALAPPDATA 'operation-v\logs'
$LogFile = Join-Path $LogDir 'start-cloudflared-tunnel.log'

function Write-Log([string]$Message) {
    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
    Add-Content -Path $LogFile -Value $line
}

try {
    if (-not (Test-Path $CloudflaredExe)) {
        Write-Log "cloudflared not found: $CloudflaredExe"
        exit 1
    }

    if (-not (Test-Path $ConfigPath)) {
        Write-Log "Missing config: $ConfigPath"
        exit 1
    }

    $existing = Get-Process -Name cloudflared -ErrorAction SilentlyContinue
    if ($null -ne $existing) {
        # A zombie Windows-service cloudflared can be running without an active tunnel connection.
        $info = & $CloudflaredExe tunnel info 4d62e764-85b1-4f2f-bc69-be5f1889822e 2>&1 | Out-String
        if ($info -notmatch 'does not have any active connection') {
            Write-Log 'cloudflared already running with active tunnel; skipping start.'
            exit 0
        }
        Write-Log 'cloudflared running but tunnel disconnected; stopping stale process(es).'
        $existing | Stop-Process -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
    }

    Write-Log 'Starting cloudflared tunnel run...'
    # --config is a "tunnel" subcommand flag (before "run"), not an argument to "run".
    Start-Process -FilePath $CloudflaredExe -ArgumentList @('tunnel', '--config', $ConfigPath, 'run') -WindowStyle Hidden
    Write-Log 'cloudflared started.'
    exit 0
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
