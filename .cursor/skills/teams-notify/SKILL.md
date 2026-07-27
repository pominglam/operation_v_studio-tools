---
name: teams-notify
description: >-
  Posts to Microsoft Teams via Incoming Webhook using TEAMS_NOTIFY_WEBHOOK_URL. When the user
  asks to notify Teams at the end of a prompt, send this turn's assistant reply (the substantive
  answer they would read in Cursor), not a generic ack. Use short summary only if they explicitly
  ask for a summary. Optional -ChatName / TEAMS_NOTIFY_CHAT_NAME for the Cursor thread title. Never
  commit webhook URLs.
---

# Teams notify (Incoming Webhook)

## Chat / thread name (optional)

Cursor does **not** expose the composer chat title to shell scripts automatically. To show it in Teams:

1. **Preferred:** pass **`-ChatName "…"`** using the thread title as shown in Cursor (copy from the chat tab / sidebar).
2. **Fallback:** set a Windows User env var **`TEAMS_NOTIFY_CHAT_NAME`** if you want a stable label without passing it on every notify (same value for all notifies from that profile until you change it).

### Why the chat name is missing in Teams

The headline **only** includes a chat segment when **either**:

- the script is run with **`-ChatName "…"`**, or  
- **`TEAMS_NOTIFY_CHAT_NAME`** is set (User or process env — script resolves both).

If the agent sends **`-Title` only** (for example `Cursor - aiva-dashboard`) **and** omits **`-ChatName`**, Teams will **not** show your Cursor tab title — by design, because the script never received it.

**Agent rule:** When the user wants the tab title on mobile, **always** pass **`-ChatName`** *or* rely on **`TEAMS_NOTIFY_CHAT_NAME`**. If neither is available and the tab title is not in the message, **ask once** for the exact tab text before running the script.

### User shorthand

On the **same message** as `/teams-notify`, add a line the agent can parse:

`Teams chat title: <exact text from Cursor tab>`

Example:

```text
…your normal question…

Teams chat title: List upload mapper discussion
/teams-notify
```

The agent maps that to **`-ChatName`** (do not put skill docs in Teams; only map the title).

**Headline** (first line of the Teams `text` payload; script uses **ASCII ` - `** so mobile Teams does not garble separators):

- **`-ChatName` set, `-Title` omitted** → `Cursor - <workspace folder> - <ChatName>`
- **Both `-Title` and `-ChatName` set** → `<Title> - <ChatName>`
- **Only `-Title` set** → `<Title>` (unchanged)

The workspace folder is the **current directory’s final segment** when the script runs (use repo root: `aiva-dashboard`).

When the user asks to include the chat name, the agent should use **`-ChatName`** (or ask the user for the exact tab title if it is not visible).

## When to use

- The user explicitly asks to **notify Teams**, **post to Teams**, **Teams ping**, **/teams-notify**, or **send this to Teams** (often at the **end** of a turn).
- Only run when asked; do **not** notify by default on every reply.

## What to send (important)

**Default:** `-Message` (or `-MessageFile`) must carry **the same substantive content as this turn’s assistant reply** — what the user would read in Cursor on desktop — so they can read it on Teams mobile.

**Hard rule:** Do **not** fill Teams with **skill documentation**, **setup instructions**, or **“what you should see”** copy unless the user explicitly asked for a **connectivity test**, **how-to**, or **demo** of teams-notify itself.

Examples of **forbidden** filler when the user only said `/teams-notify` or “notify Teams” at the end of a normal task:

- Explaining how `-ChatName`, env vars, or webhooks work  
- Describing what the headline “will look like” instead of sending the actual answer  
- Placeholder text like “paste the full assistant reply here”

`/teams-notify` alone still means: mirror **the real answer to their actual question** for this turn (including apologies, fixes, code explanations—whatever you were going to say in chat).

- Do **not** replace it with a generic test line, “done”, or a stub unless the user clearly asked only for a connectivity test.
- If the reply is **extremely long**, you may either:
  - raise `-MaxBodyChars` up toward `28000` (still capped by script), or
  - send an **honest abbreviated version** that preserves conclusions, commands, and risks, and state in the body that the full answer remains in Cursor.

**Safety:** strip secrets (tokens, `.env`, passwords, full webhook URLs). Code snippets are fine if the user’s prompt implied sharing them.

## Requirements

1. **Webhook URL** is stored only in **`TEAMS_NOTIFY_WEBHOOK_URL`**:
   - Prefer **Windows User** environment variable (UI: *Environment Variables* → User → New).
   - After changing User env vars, **fully restart Cursor** so integrated terminals inherit `$env:TEAMS_NOTIFY_WEBHOOK_URL`.
   - If the process env is empty but User env is set, the script also reads **User** scope from the registry (works before restart).

2. **Never** put the webhook URL in `SKILL.md`, scripts, `.env` committed files, or chat logs in the repo.

## How to send (from repo root)

### A) Inline (fine for short / medium replies)

Use a PowerShell here-string so newlines and quotes are safe:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File ".cursor/skills/teams-notify/scripts/Send-TeamsNotify.ps1" `
  -ChatName "PR preflight parallel mode" `
  -Message @'
…verbatim substantive assistant reply for this turn (no skill filler)…
'@
```

Or keep a custom title **and** append the chat name:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File ".cursor/skills/teams-notify/scripts/Send-TeamsNotify.ps1" `
  -Title "Cursor - aiva-dashboard" `
  -ChatName "Teams notify tweaks" `
  -Message @'
…full reply…
'@
```

Optional title omitted:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File ".cursor/skills/teams-notify/scripts/Send-TeamsNotify.ps1" -Message @'
…full reply…
'@
```

Use **single-quoted** here-strings (`@' ... '@`) so `$` in code/output is not expanded by PowerShell.

### B) File (best for long replies or heavy escaping)

Write UTF-8 text to a **temp file** (repo-ignored path such as `$env:TEMP`), then:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File ".cursor/skills/teams-notify/scripts/Send-TeamsNotify.ps1" `
  -ChatName "Long thread - mobile catch-up" `
  -MessageFile "$env:TEMP\teams-notify-body.txt"
```

Delete the temp file after a successful send if you created it only for this notify.

## Limits

- Default `-MaxBodyChars` is **12000**. Incoming Webhook payloads still have practical limits; increase with `-MaxBodyChars 25000` if needed.
- If truncated, the script appends a short note so the user knows to open Cursor for the full thread.

## If it fails

- **`TEAMS_NOTIFY_WEBHOOK_URL is not set`**: confirm variable name spelling; set User env var; restart Cursor; or run from a shell where `$env:TEAMS_NOTIFY_WEBHOOK_URL` is defined.
- **HTTP / Teams errors**: webhook may have been rotated or connector disabled; user must create a new Incoming Webhook or Workflow URL and update the env var.

## Optional: personal copy

To keep workflow off shared repos, copy this skill to `~/.cursor/skills/teams-notify/` and adjust paths.
