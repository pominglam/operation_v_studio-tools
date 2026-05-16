# Sync progress (`/sync-progress`)

**Page:** `resources/js/pages/SyncProgressPage.vue`  
**Component:** `resources/js/components/jobs/DebugLogDialog.vue`

## Purpose

Unified UI for Laravel **Bus batches** surfaced through:

- **`GET /api/v1/job-batches`** (+ `limit` query **default harness** chooses `50`).
- **`GET /api/v1/job-batches/{id}`**
- **`GET /api/v1/job-batches/{id}/items?limit=`** segmented summary (**queued/running/done**) with richer debug payloads.
- **`POST /api/v1/job-batches/{id}/cancel`**
- **`POST /api/v1/job-batches/{id}/resume`** (shows success/error ribbons when queue supports remediation)

Primary human workflows:

| Origin | Typical batch contents |
| --- | --- |
| **Products → Sync missing PDP info** (`Sync missing PDP info`) | `sync_missing_pdp_info` (**`SyncPlamodAssetsJob`** chain) |
| **Bulk rename Plamod assets** before Shopify content CSV | Rename job batches + **`auto_export` hook** (below) |
| **Inventory-check detail bulk rename export** path | Same auto-export interplay |
| **Purchase order bulk recrawl/export launch** parity | Mirrors product flow when routed here with `batch_id` query |

## Query string ergonomics

- **`?batch_id=<laravel_batch_uuid>`** — auto-select manual field + hydrate detail panes (`onMounted` + watchers).
- **`?auto_export=shopify_content`** — after rename completion, **`sessionStorage` key** `auto_export_shopify_content:{batchId}` JSON **`{ ids: string[] }`** instructs UI to **`POST`** Shopify content **`prepare`** for those IDs then download (**guarded flag `auto_exportTriggered`** so one-shot).

Fallback if storage inaccessible: operator must run Shopify content prepare manually (`ProductsPage` / inventory detail export dialog).

## Visual surface

Left/history pane lists recent batches (name/total counts/timestamps). Selecting row loads textual **progress_percent** ladder + **`failed_jobs` emphasis**.

Item pane:

- Tabs/sections separating **queued vs running vs done** subsets (limited item fetch `limit` param default `25`).
- Each row exposes **SKU / uuid / vendor / statuses / attempts / timings**.
- Rows with textual **`debug_log`** open modal dialog (**`DebugLogDialog`**) prettifying multi-line crawler traces gathered during PDP sync jobs (`hasDebugLog` guard).

Polling timer toggles whenever **`selectedId`** active—pauses teardown on route unmount.

## Operator actions

| Button | Calls |
| --- | --- |
| **Refresh list** | `loadList` |
| **Cancel batch** cooperative | `cancel` POST |
| **Resume** (when enabled UX-side) | `resume` POST (surfaces **`resumeMessage` / `resumeError`**) |

## Deep links surfaced elsewhere

Inside **`ProductsPage`** near **Sync missing PDP info** shortcut anchor `/sync-progress` plus contextual **Open** hyperlink when ephemeral **`syncBatchId`** known inline.
