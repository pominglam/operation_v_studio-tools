# Registers Windows startup for:
#   1) Named Cloudflare tunnel (ovs.centredentairevsl.com -> localhost:8020)
#   2) pricing-tool Docker Compose stack (after Docker Desktop is ready)
#
# Run once (elevated recommended to remove a broken Cloudflared Windows service if present):
#   powershell -ExecutionPolicy Bypass -File .\scripts\windows\install-pc-startup.ps1
#
# Uninstall:
#   powershell -ExecutionPolicy Bypass -File .\scripts\windows\install-pc-startup.ps1 -Uninstall

[CmdletBinding()]
param(
    [switch]$Uninstall
)

$ErrorActionPreference = 'Stop'

$ProjectRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$TunnelScript = Join-Path $PSScriptRoot 'start-cloudflared-tunnel.ps1'
$ContainerScript = Join-Path $PSScriptRoot 'start-pricing-tool-containers.ps1'
$CloudflaredExe = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$TunnelTaskName = 'Operation-V Cloudflare Tunnel'
$ContainerTaskName = 'Operation-V Pricing Tool Containers'

function Test-IsAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Remove-CloudflaredWindowsServiceIfPresent {
    $existing = Get-Service -Name 'Cloudflared' -ErrorAction SilentlyContinue
    if ($null -eq $existing) {
        return
    }

    Write-Host 'Removing Cloudflared Windows service (logon task uses your user config instead)...'
    taskkill /F /IM cloudflared.exe 2>$null | Out-Null
    Start-Sleep -Seconds 2

    if ($existing.Status -ne 'Stopped') {
        Stop-Service -Name 'Cloudflared' -Force -ErrorAction SilentlyContinue
        $deadline = (Get-Date).AddSeconds(15)
        while ((Get-Service -Name 'Cloudflared').Status -ne 'Stopped' -and (Get-Date) -lt $deadline) {
            Start-Sleep -Seconds 1
        }
    }

    if (Test-Path $CloudflaredExe) {
        & $CloudflaredExe service uninstall 2>$null
    }

    if (Get-Service -Name 'Cloudflared' -ErrorAction SilentlyContinue) {
        sc.exe delete Cloudflared | Out-Null
        Start-Sleep -Seconds 2
    }

    taskkill /F /IM cloudflared.exe 2>$null | Out-Null
}

function Register-LogonTask {
    param(
        [string]$TaskName,
        [string]$ScriptPath,
        [string]$Description
    )

    if (-not (Test-Path $ScriptPath)) {
        throw "Missing script: $ScriptPath"
    }

    $action = New-ScheduledTaskAction `
        -Execute 'powershell.exe' `
        -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$ScriptPath`""

    $trigger = New-ScheduledTaskTrigger -AtLogon -User $env:USERNAME

    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -ExecutionTimeLimit ([TimeSpan]::Zero)

    $existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($null -ne $existing) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    }

    Register-ScheduledTask `
        -TaskName $TaskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Description $Description | Out-Null

    Write-Host "Registered scheduled task: $TaskName (at logon)"
}

function Install-StartupTasks {
    Register-LogonTask `
        -TaskName $TunnelTaskName `
        -ScriptPath $TunnelScript `
        -Description 'Starts named Cloudflare tunnel for ovs.centredentairevsl.com.'

    Register-LogonTask `
        -TaskName $ContainerTaskName `
        -ScriptPath $ContainerScript `
        -Description 'Starts pricing-tool Docker Compose after Docker Desktop is ready.'
}

function Remove-StartupTasks {
    foreach ($name in @($TunnelTaskName, $ContainerTaskName)) {
        $existing = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($null -ne $existing) {
            Unregister-ScheduledTask -TaskName $name -Confirm:$false
            Write-Host "Removed scheduled task: $name"
        }
    }
}

if ($Uninstall) {
    Remove-StartupTasks
    Get-Process -Name cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue

    if (Test-IsAdmin) {
        Remove-CloudflaredWindowsServiceIfPresent
    }

    Write-Host 'PC startup hooks removed.'
    exit 0
}

if (Test-IsAdmin) {
    Remove-CloudflaredWindowsServiceIfPresent
} else {
    Write-Warning 'Not elevated — if a Cloudflared Windows service exists, re-run elevated to remove it.'
}

Install-StartupTasks

Write-Host ''
Write-Host 'Done. At each Windows logon:'
Write-Host '  - Named Cloudflare tunnel starts (ovs.centredentairevsl.com)'
Write-Host '  - Docker containers start after Docker Desktop is ready'
Write-Host "  - Logs: $env:LOCALAPPDATA\operation-v\logs\"

# Start now without waiting for next logon
Write-Host ''
Write-Host 'Starting tunnel and containers now...'
& powershell -NoProfile -ExecutionPolicy Bypass -File $TunnelScript
Start-Sleep -Seconds 2
& powershell -NoProfile -ExecutionPolicy Bypass -File $ContainerScript
