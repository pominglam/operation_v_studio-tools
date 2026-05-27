# Maintenance (`/maintenance`)

**Page:** `resources/js/pages/MaintenancePage.vue` (~1400 LOC — treat sections as isolated “cards”).

**Shared UI:** **`ConfirmDialog`** gates each destructive/long-running mutation.

Persisted prefs key: **`page_state:maintenance`** hydrating multi-select **`selectedTypes` / `selectedVendors` / site keys / recrawl status toggles**.

---

## External crawl rate limit

- Loads **`GET /api/v1/maintenance/external-rate-limit`** → displays/edits **`externalHitsPerMinute`** integer.
- Saves via **`PUT /api/v1/maintenance/external-rate-limit`** with busy + toast style inline messages (**`externalHitsMessage|Error`**).

Purpose: coarse throttle for scripted crawlers honoring ops safety (see **`ExternalRateLimitService`** backend).

---

## Shopify sync & demand

**Card on Maintenance** + **`/shopify/webhooks`** log browser (`ShopifyWebhookLogsPage.vue`).

| Action | Endpoint |
| --- | --- |
| Read sync health + reconcile interval | **`GET /api/v1/shopify/settings`** |
| Save reconcile interval (hours, default 12) | **`PUT /api/v1/shopify/settings`** `{ order_reconcile_interval_hours }` |
| List webhook logs (paginated, filters) | **`GET /api/v1/shopify/webhook-logs`** |
| Webhook log detail + payload | **`GET /api/v1/shopify/webhook-logs/{id}`** |
| Repull all historical orders (queued) | **`POST /api/v1/shopify/orders/historical-backfill`** — requires Shopify **`read_all_orders`** scope (with `read_orders` or `write_orders`); without it Shopify only returns ~60 days. Re-OAuth at **`/shopify/oauth/install`** after adding the scope. Sync log **`counts_json.oldest_order_created_at`** confirms how far back the pull reached. |
| Rebuild demand rollups (queued) | **`POST /api/v1/shopify/demand/rebuild-rollups`** |
| Pull Shopify inventory → `products.available_qty` (queued) | **`POST /api/v1/shopify/inventory/pull-to-products`** |

**Status panel (persistent):** **Refresh status** reloads **`GET /api/v1/shopify/settings`**. Response includes order sync timestamps, next reconcile due, last webhook, and a **`tasks[]`** table (reconcile, historical backfill, demand rebuild, inventory pull) with **`status`** (`never` / `queued` / `running` / `completed` / `failed`), last finished time, counts, and errors. Dispatching a maintenance action writes a **`shopify_sync_logs`** row with **`status=queued`** immediately (survives page refresh); the queue worker promotes it to **`running`** then **`completed`** / **`failed`**. A log row in **`running`** takes precedence over a reserved row still present in **`jobs`**. Inventory pull clamps negative Shopify available quantities to **`0`** before writing **`products.available_qty`** (unsigned column; also enforced on **`Product`** save). Only **`ACTIVE`** Shopify catalog variants contribute inventory (**`ARCHIVED`** / **`DRAFT`** mirror rows are ignored); order line history is unchanged. Demand rollups exclude **cancelled** Shopify orders (**`cancelled_at`** set or financial status **`VOIDED`**); run **Rebuild demand rollups** after deploying this logic so existing counts drop cancelled lines. **After pulling new PHP code locally, restart the `queue` compose service** so long-running `queue:work` reloads changes (otherwise maintenance jobs may run stale bytecode until `--max-time` restart).

**Scheduler:** Docker service **`scheduler`** runs `schedule:work`; every minute checks whether order reconcile is due (interval from settings). **Queue** worker processes Shopify jobs on **`default`**.

**Products demand UI:** **`4 wk sold`** column on Products; click opens **`ProductDemandDetailDialog`** → **`GET /api/v1/products/{uuid}/demand`**.

---

## External access controls

Panels:

| Sub-feature | Endpoint(s) |
| --- | --- |
| Master enable + passwordConfigured flags | **`GET/PUT /api/v1/maintenance/external-access`** |
| Operational Cloudflare **`trycloudflare`** tunnel bridging app for Shopify images / remote demos | Auxiliary service fields returned on GET (**`tunnel_url`**, **`running`**, reachability probes) |

**Start / Refresh tunnel** respects `canStartExternalAccessTunnel` computed guard (won’t spam if lacking configured password—even if weird states arise).

📎 Aligns behaviorally with **employee/admin role gating** outlined in **`shared/roles-and-access.md`**.

---

## Maintenance notes

Markdown/plaintext scratchpad persisted:

| Action | Endpoint |
| --- | --- |
| Hydrate textarea | **`GET /api/v1/maintenance/notes`** |
| Save edits | **`PUT /api/v1/maintenance/notes`** |

Operators use this as running log / procedure hints.

---

## Database backups & restore

| Step | Endpoint | UX guard |
| --- | --- | --- |
| List recent artifacts | **`GET /api/v1/maintenance/db-backups?limit=`** | Renders **`size_bytes`**, **`created_by`**, timestamps |
| Create logical dump | **`POST /api/v1/maintenance/db-backups`** JSON **`{ description }`** | Spinner + emerald summary banner |
| Restore selected artifact | **`POST /api/v1/maintenance/db-backups/restore`** **`{ backup_uuid }`** | **ConfirmDialog** warns destructive |

Operational constraints (mysqldump/mysql binaries) enumerated in **`docs/requirements/maintenance-db-backups.md`**.

---

## Flush products table

**Confirm dialog** warns **Deletes ALL products** → issues **`DELETE /api/v1/products`** routed to **`ProductMaintenanceController`** → **`flushAll()`** catastrophic wipe for greenfield resets.

⚠ Extremely dangerous—coordinate backups first.

---

## Refresh latest product costs

Non-destructive recompute (**`POST /api/v1/maintenance/refresh-latest-costs`**) invoking **`ProductLatestCostCacheService::recomputeAll`** — summarizes **`matched`** / **`updated`**.

Confirmation copy clarifies breadth (recalculates cache columns for SKU universe).

---

## Product type tooling

Two distinct maintenance passes with explicit confirm copy differences:

| Button | Endpoint | Intent |
| --- | --- | --- |
| **Backfill missing types only** | **`POST /api/v1/products/backfill-types`** | Fills empty types inferred from textual description heuristics (see service) |
| **Recompute all types** (stronger language) | **`POST /api/v1/products/recompute-types`** | Re-derives type classification even when previously set (**danger** confirm variant) |

---

## Targeted site recrawl (maintenance)

Loads:

- Competitor **`sites`** definitions via **`GET /api/v1/price-research/filter-options`** (`availableSites`).
- Product taxonomy buckets via **`GET /api/v1/products/filter-options`** (`types`, `vendors`) for narrowing.

Operator selects **`site_keys`**, freshness bucket **`status`** (`recrawlStatus`: **`any | fresh | expired`**), **`quote_status`** filter (**`any | error`**), optional **types**/**vendors**.

**Run** triggers:

```json
POST /api/v1/price-research/run
{
  "force": true,
  "site_keys": ["…"],
  "status": "<any|fresh|expired>",
  "quote_status": "<any|error>",
  "types": ["…"],
  "vendors": ["…"]
}
```

Acceptance treats **HTTP 200 or 202** as success UX-side.

Contrast: **Force refresh all** posts only **`{ "force": true }`** (whole catalog/all site coverage per backend semantics).

---

## AliExpress cookie upload (optional)

Paste cookies JSON (**must deserialize to an array**) → **`POST /api/v1/price-research/aliexpress/cookies`** with **`{ cookies: [...] }`**.

Success message shows uploaded count (**`response.data.count`** fallback to array length).

---

## Force refresh all competitor prices

Confirm dialog → **`POST /api/v1/price-research/run`** **`{ "force": true }`**. Treats **200/202** as queued.

---

## Reset stuck latest research run state

Writes **`POST /api/v1/price-research/runs/reset`** ({} body). On non-200, surfaces API **`message`** in error ribbon.
