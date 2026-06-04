# Windows PC startup (pricing-tool + Cloudflare tunnel)

## What gets installed

| Component | Mechanism | Purpose |
| --- | --- | --- |
| **Named Cloudflare tunnel** | Scheduled task at **user logon** | `ovs.centredentairevsl.com` → `http://localhost:8020` (reads `%USERPROFILE%\.cloudflared\config.yml`) |
| **Docker Compose stack** | Scheduled task at **user logon** | `docker compose up -d` after Docker Desktop is ready |

Both run in your user session so tunnel credentials and Docker Desktop work reliably.

## Install

```powershell
Set-Location C:\Users\plam\workspace\operation-v\pricing-tool
powershell -ExecutionPolicy Bypass -File .\scripts\windows\install-pc-startup.ps1
```

If `ovs.centredentairevsl.com` shows **Error 1033** or a `Cloudflared` service is stuck **StopPending**, run this **once as Administrator**:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\windows\fix-cloudflared-tunnel-now.ps1
```

## Uninstall

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\windows\install-pc-startup.ps1 -Uninstall
```

## Logs

- `%LOCALAPPDATA%\operation-v\logs\start-cloudflared-tunnel.log`
- `%LOCALAPPDATA%\operation-v\logs\start-pricing-tool-containers.log`

## Manual checks

```powershell
Get-ScheduledTask -TaskName 'Operation-V*'
Get-Process cloudflared -ErrorAction SilentlyContinue
& "C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel info 4d62e764-85b1-4f2f-bc69-be5f1889822e
docker compose -f C:\Users\plam\workspace\operation-v\pricing-tool\compose.yml ps
```
