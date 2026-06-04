# One-time fix: remove broken Cloudflared Windows service, start user tunnel.
# Must run elevated (Administrator).

#Requires -RunAsAdministrator

$ErrorActionPreference = 'Stop'

$CloudflaredExe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$ConfigPath = Join-Path $env:USERPROFILE '.cloudflared\config.yml'
$InstallScript = Join-Path $PSScriptRoot 'install-pc-startup.ps1'
$TunnelScript = Join-Path $PSScriptRoot 'start-cloudflared-tunnel.ps1'

Write-Host 'Stopping cloudflared processes...'
taskkill /F /IM cloudflared.exe 2>$null | Out-Null
Start-Sleep -Seconds 2

$svc = Get-Service -Name 'Cloudflared' -ErrorAction SilentlyContinue
if ($null -ne $svc) {
    Write-Host "Cloudflared service status: $($svc.Status)"
    if ($svc.Status -ne 'Stopped') {
        Stop-Service -Name 'Cloudflared' -Force -ErrorAction SilentlyContinue
        $deadline = (Get-Date).AddSeconds(15)
        while ((Get-Service Cloudflared).Status -ne 'Stopped' -and (Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 1
        }
    }

    if (Test-Path $CloudflaredExe) {
        Write-Host 'Uninstalling Cloudflared Windows service...'
        & $CloudflaredExe service uninstall 2>$null
    }

    if (Get-Service -Name 'Cloudflared' -ErrorAction SilentlyContinue) {
        Write-Host 'Service still present; deleting via sc.exe...'
        sc.exe delete Cloudflared | Out-Null
        Start-Sleep -Seconds 2
    }
}

taskkill /F /IM cloudflared.exe 2>$null | Out-Null
Start-Sleep -Seconds 1

if (-not (Test-Path $ConfigPath)) {
    throw "Tunnel config missing: $ConfigPath"
}

Write-Host 'Registering logon tasks and starting tunnel...'
& powershell -NoProfile -ExecutionPolicy Bypass -File $InstallScript

Start-Sleep -Seconds 5

$info = & $CloudflaredExe tunnel info 4d62e764-85b1-4f2f-bc69-be5f1889822e 2>&1 | Out-String
Write-Host $info

if ($info -match 'active connection') {
    Write-Host 'Tunnel connector is up.'
} else {
    Write-Host 'WARNING: Tunnel may still be connecting. Check https://ovs.centredentairevsl.com/ in ~30s.'
}
