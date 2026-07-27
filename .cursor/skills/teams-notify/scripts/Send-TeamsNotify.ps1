# Sends a Teams Workflows-compatible MessageCard to an incoming webhook.
# Requires TEAMS_NOTIFY_WEBHOOK_URL (process env or Windows User env — see skill).

param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Inline')]
    [string]$Message,

    [Parameter(Mandatory = $true, ParameterSetName = 'File')]
    [string]$MessageFile,

    [Parameter(Mandatory = $false)]
    [string]$Title = '',

    [Parameter(Mandatory = $false)]
    [string]$ChatName = '',

    [Parameter(Mandatory = $false)]
    [ValidateRange(500, 28000)]
    [int]$MaxBodyChars = 12000
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-TeamsWebhookUri {
    $uri = $env:TEAMS_NOTIFY_WEBHOOK_URL
    if (-not [string]::IsNullOrWhiteSpace($uri)) {
        return $uri.Trim()
    }

    $user = [System.Environment]::GetEnvironmentVariable('TEAMS_NOTIFY_WEBHOOK_URL', 'User')
    if (-not [string]::IsNullOrWhiteSpace($user)) {
        return $user.Trim()
    }

    throw @(
        'TEAMS_NOTIFY_WEBHOOK_URL is not set.'
        'Set it as a Windows User environment variable, then restart Cursor; or see .cursor/skills/teams-notify/SKILL.md.'
    ) -join ' '
}

function Get-BodySourceText {
    if ($PSCmdlet.ParameterSetName -eq 'File') {
        if (-not (Test-Path -LiteralPath $MessageFile)) {
            throw "MessageFile not found: $MessageFile"
        }
        return (Get-Content -LiteralPath $MessageFile -Raw -Encoding utf8).Trim()
    }

    return $Message.Trim()
}

$uri = Get-TeamsWebhookUri
$source = Get-BodySourceText

if ([string]::IsNullOrWhiteSpace($source)) {
    throw 'Message body is empty.'
}

function Resolve-ChatName {
    param([string]$Explicit)
    $n = $Explicit.Trim()
    if (-not [string]::IsNullOrWhiteSpace($n)) {
        return $n
    }

    $envName = $env:TEAMS_NOTIFY_CHAT_NAME
    if (-not [string]::IsNullOrWhiteSpace($envName)) {
        return $envName.Trim()
    }

    $userName = [System.Environment]::GetEnvironmentVariable('TEAMS_NOTIFY_CHAT_NAME', 'User')
    if (-not [string]::IsNullOrWhiteSpace($userName)) {
        return $userName.Trim()
    }

    return ''
}

function Build-NotifyHeadline {
    param(
        [string]$Title,
        [string]$ChatNameResolved
    )

    $workspaceName = Split-Path -Leaf ((Get-Location).Path.TrimEnd('\', '/'))

    if (-not [string]::IsNullOrWhiteSpace($ChatNameResolved)) {
        # ASCII hyphens in the headline avoid mojibake on some Teams clients if UTF-8 is mishandled.
        if (-not [string]::IsNullOrWhiteSpace($Title)) {
            return ($Title.Trim() + ' - ' + $ChatNameResolved.Trim())
        }

        return ('Cursor - ' + $workspaceName + ' - ' + $ChatNameResolved.Trim())
    }

    if (-not [string]::IsNullOrWhiteSpace($Title)) {
        return $Title.Trim()
    }

    return ''
}

$resolvedChatName = Resolve-ChatName -Explicit $ChatName
$headline = Build-NotifyHeadline -Title $Title -ChatNameResolved $resolvedChatName

$messageText = $source

if ($messageText.Length -gt $MaxBodyChars) {
    $suffix = "`n`n[Truncated for Teams; full reply remains in Cursor.]"
    $take = $MaxBodyChars - $suffix.Length
    if ($take -lt 1) {
        $take = $MaxBodyChars
        $suffix = [char]0x2026
    }
    $messageText = $messageText.Substring(0, [Math]::Max(0, $take)).TrimEnd() + $suffix
}

# Teams Workflows webhooks accept connector MessageCard payloads and surface them
# more reliably than bare text payloads in Power Automate-backed flows.
$summary = if ([string]::IsNullOrWhiteSpace($headline)) {
    'Cursor notification'
}
else {
    $headline
}

$payloadObject = [ordered]@{
    '@type' = 'MessageCard'
    '@context' = 'http://schema.org/extensions'
    summary = $summary
    themeColor = '0076D7'
    text = $messageText
}

if (-not [string]::IsNullOrWhiteSpace($headline)) {
    $payloadObject['title'] = $headline
}

# PowerShell 5.1 may treat string bodies as non-UTF8; send explicit UTF-8 bytes so Teams shows Unicode correctly.
$payloadJson = ($payloadObject | ConvertTo-Json -Compress)
$utf8NoBom = New-Object System.Text.UTF8Encoding $false
$payloadBytes = $utf8NoBom.GetBytes($payloadJson)
$response = Invoke-WebRequest -Uri $uri -Method Post -Body $payloadBytes -ContentType 'application/json; charset=utf-8'
Write-Host "Teams notify: accepted (HTTP $($response.StatusCode))."
