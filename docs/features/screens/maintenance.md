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
