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

| Action                                                     | Endpoint                                                                                                                                                                                                                                                                                                                                           |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Read sync health + reconcile interval                      | **`GET /api/v1/shopify/settings`**                                                                                                                                                                                                                                                                                                                 |
| Save reconcile interval (minutes, default 30, min 15)      | **`PUT /api/v1/shopify/settings`** `{ order_reconcile_interval_minutes }` (legacy `{ order_reconcile_interval_hours }` still accepted)                                                                                                                                                                                                               |
| List webhook logs (paginated, filters)                     | **`GET /api/v1/shopify/webhook-logs`**                                                                                                                                                                                                                                                                                                             |
| Webhook log detail + payload                               | **`GET /api/v1/shopify/webhook-logs/{id}`**                                                                                                                                                                                                                                                                                                        |
| Repull all historical orders (queued)                      | **`POST /api/v1/shopify/orders/historical-backfill`** — requires Shopify **`read_all_orders`** scope (with `read_orders` or `write_orders`); without it Shopify only returns ~60 days. Re-OAuth at **`/shopify/oauth/install`** after adding the scope. Sync log **`counts_json.oldest_order_created_at`** confirms how far back the pull reached. Also writes **`customer_gid` / `customer_email` / `customer_phone`** on each `shopify_orders` row. |
| Rebuild demand rollups (queued)                            | **`POST /api/v1/shopify/demand/rebuild-rollups`**                                                                                                                                                                                                                                                                                                  |
| Pull Shopify inventory → `products.available_qty` (queued) | **`POST /api/v1/shopify/inventory/pull-to-products`**                                                                                                                                                                                                                                                                                              |

**Status panel (persistent):** The page reloads **`GET /api/v1/shopify/settings`** automatically every **5 seconds** while the browser tab is visible; **Refresh status** remains available for an immediate manual refresh. Background failures preserve the last displayed snapshot instead of clearing the table. Response includes order sync timestamps, next reconcile due, last webhook, and a **`tasks[]`** table (reconcile, historical backfill, demand rebuild, inventory pull) with **`status`** (`never` / `queued` / `running` / `completed` / `failed`), last finished time, counts, and errors. Dispatching a maintenance action writes a **`shopify_sync_logs`** row with **`status=queued`** immediately (survives page refresh); the queue worker promotes it to **`running`** then **`completed`** / **`failed`**. A log row in **`running`** takes precedence over a reserved row still present in **`jobs`**. Inventory pull clamps negative Shopify available quantities to **`0`** before writing **`products.available_qty`** (unsigned column; also enforced on **`Product`** save). Inventory levels are fetched in GraphQL **`nodes(ids:)`** batches (default **200** items per request, `SHOPIFY_INVENTORY_ITEM_BATCH_SIZE`, hard cap **250**) instead of one Admin API call per SKU. Only **`ACTIVE`** Shopify catalog variants contribute inventory (**`ARCHIVED`** / **`DRAFT`** mirror rows are ignored); order line history is unchanged. Demand rollups exclude **cancelled** Shopify orders (**`cancelled_at`** set or financial status **`VOIDED`**); run **Rebuild demand rollups** after deploying this logic so existing counts drop cancelled lines. **After pulling new PHP code locally, restart the `queue` compose service** so long-running `queue:work` reloads changes (otherwise maintenance jobs may run stale bytecode until `--max-time` restart).

**Scheduler:** Docker service **`scheduler`** runs `schedule:work`; every minute checks whether order reconcile is due (interval from settings). **Queue** worker processes Shopify jobs on **`default`**.

---

## Model kit storefront index cache

ERP rebuilds one compact JSON catalog and upserts it into the Shopify theme snippet `snippets/ovs-model-kit-index-cache.liquid` (live Rise + AI Dev by default). Collection pages embed `#ovs-mk-index-cache`; theme JS hydrates the full MK index from that file and synthesizes filter cards so the next visitor does not wait on sequential Shopify section fetches.

Compact purchase flags per row: **`a`** = ERP sellable (in stock), **`pc`** = store preorder actually closed (status or past close date), **`op`** = open store preorder. Open preorders with 0 ERP qty stay `pc=0` / `op=1` so filtered hubs do not show **Preorder closed** or **Sold out**. Synthesized filter cards still render Dawn listing CTAs from those flags (**Add to cart** / **Sold out** / **Pre-order** / **Preorder closed**) after hydrating the Shopify variant id from `/products/{handle}.js`. Homepage featured / latest-arrival cards do **not** show those buttons (see [listing CTAs](../../requirements/model-kit-collection-filters.md#listing-ctas)).

| Trigger | Behavior |
| --- | --- |
| Successful Shopify **model-kit** product upsert | After ERP is already on Shopify, marks the cache dirty and queues **`RebuildModelKitStorefrontIndexJob`** (unique on the `shopify` queue, 8s delay). The job **recreates the whole file**, not a per-SKU patch. Bursts coalesce. T&S / other department upserts do not poke. ERP-only saves do not poke — the storefront would still show the old Shopify product. |
| Nightly | **`php artisan storefront:model-kit-index-rebuild`** at 03:45 America/Toronto. |
| Manual | Same artisan command. |

Disable in tests via `SHOPIFY_MK_INDEX_REBUILD_ENABLED=false`. Theme git keeps an empty stub; do not overwrite the live generated file with the stub.

---

## Model kit collection filter manifest

Regenerates theme + docs artifacts from **`ModelKitShelfCatalog`** when shelves or filter profiles change.

| Action | Endpoint |
| --- | --- |
| Regenerate manifest (typically &lt; 1 s) | **`POST /api/v1/maintenance/model-kit-collection-filter-manifest`** |

Writes:

- `ovs-shopify-theme/docs/model-kit-collection-filters.json`
- `ovs-shopify-theme/snippets/ovs-model-kit-filter-handles.liquid`
- `ovs-shopify-theme/snippets/ovs-model-kit-filter-profile.liquid`
- `ovs-shopify-theme/assets/ovs-model-kit-collection-filters-handles.js`
- `docs/requirements/model-kit-collection-filters.md`

Requires **`OVS_SHOPIFY_THEME_PATH`** (or sibling `../ovs-shopify-theme`). Does **not** push to Shopify — push theme files to AI Dev separately.

CLI equivalent: **`php artisan storefront:model-kit-collection-filter-manifest-generate`**.

**Products demand UI:** **`4 wk sold`** column on Products; click opens **`ProductDemandDetailDialog`** → **`GET /api/v1/products/{uuid}/demand`**.

---

## External access controls

Panels:

| Sub-feature                                                                                      | Endpoint(s)                                                                                     |
| ------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| Master enable + passwordConfigured flags                                                         | **`GET/PUT /api/v1/maintenance/external-access`**                                               |
| Operational Cloudflare **`trycloudflare`** tunnel bridging app for Shopify images / remote demos | Auxiliary service fields returned on GET (**`tunnel_url`**, **`running`**, reachability probes) |

**Start / Refresh tunnel** respects `canStartExternalAccessTunnel` computed guard (won’t spam if lacking configured password—even if weird states arise).

📎 Aligns behaviorally with **employee/admin role gating** outlined in **`shared/roles-and-access.md`**.

---

## Maintenance notes

Markdown/plaintext scratchpad persisted:

| Action           | Endpoint                            |
| ---------------- | ----------------------------------- |
| Hydrate textarea | **`GET /api/v1/maintenance/notes`** |
| Save edits       | **`PUT /api/v1/maintenance/notes`** |

Operators use this as running log / procedure hints.

---

## Special order customer message template

Editable DM template used on **`/special-orders/:id`** (**Copy message**). Stored in **`maintenance_notes`** key **`special_order_customer_message`**.

| Placeholder | Replaced with |
| --- | --- |
| `{product_name}` | Order product name |
| `{price}` | Customer price formatted e.g. `$580 CAD` |
| `{deposit_percent}` | Deposit % (e.g. `20`) |

| Action | Endpoint |
| --- | --- |
| Load template + default + preview metadata | **`GET /api/v1/maintenance/special-order-customer-message-template`** |
| Save template (all three placeholders required) | **`PUT`** same path **`{ body }`** |
| Reset to built-in default | **`PUT`** same path **`{ reset: true }`** |

Maintenance card includes live preview with sample **CCSTOYS EVA 02 / $580 / 20%**.

---

## OPV catalog margin

Multiplier and default deposit for **Plamod pick-list** suggested sell $ and **store preorder** open. Stored in **`maintenance_notes`** key **`opv_catalog_pricing`**. Sell $ uses the **same catalog formula as PO set-prices** (cost × multiplier, closest X.99, ties go up). PO set-prices still uses its own 1.5× unless changed separately.

| Setting | Built-in default |
| --- | --- |
| Price multiplier (× PO cost) | **1.50** |
| Default store-preorder deposit | **20%** |

Per-kit Sell $ can still be overridden on **Open & push to Shopify**.

| Action | Endpoint |
| --- | --- |
| Load settings + defaults + `is_default` | **`GET /api/v1/maintenance/opv-catalog-pricing`** |
| Save | **`PUT`** same path **`{ price_multiplier, default_deposit_percent }`** |
| Reset to built-in defaults | **`PUT`** same path **`{ reset: true }`** |

---

## Special order pricing caps

Maximum CAD amounts for **formula-derived** merchandiser commission and OPV margin on **`/special-orders/:id`**, plus **default merchandiser shipping** applied when a new order is created. Stored in **`maintenance_notes`** key **`special_order_pricing_caps`** (JSON).

| Setting | Built-in default |
| --- | --- |
| Merchandiser commission cap | **$50.00 CAD** |
| OPV margin cap | **$150.00 CAD** |
| Default shipping (new orders) | **100.00 RMB** |
| Shipping rate (weight mode) | **29.00 RMB/kg** |

Explicit **CAD overrides** on an individual order bypass caps; multiplier-only pricing is capped on load and when multipliers change.

| Action | Endpoint |
| --- | --- |
| Load caps + defaults + `is_default` | **`GET /api/v1/maintenance/special-order-pricing-caps`** |
| Save caps | **`PUT`** same path **`{ merchandiser_commission_cap_cad, opv_margin_cap_cad, default_shipping_cost_amount, default_shipping_cost_currency, default_shipping_cost_per_kg_cny }`** |
| Reset to built-in defaults | **`PUT`** same path **`{ reset: true }`** |

---

## Database backups & restore

| Step                      | Endpoint                                                                | UX guard                                               |
| ------------------------- | ----------------------------------------------------------------------- | ------------------------------------------------------ |
| List recent artifacts     | **`GET /api/v1/maintenance/db-backups?limit=`**                         | Renders **`size_bytes`**, **`created_by`**, timestamps |
| Create logical dump       | **`POST /api/v1/maintenance/db-backups`** JSON **`{ description }`**    | Spinner + emerald summary banner                       |
| Restore selected artifact | **`POST /api/v1/maintenance/db-backups/restore`** **`{ backup_uuid }`** | **ConfirmDialog** warns destructive                    |

Operational constraints (mysqldump/mysql binaries) enumerated in **`docs/requirements/maintenance-db-backups.md`**.

**Scheduled automation** (scheduler container, `America/Toronto`):

- Daily **`db:backup`** — `created_by=system`, description `Scheduled daily backup`
- Weekly **`db:backup:purge`** — retention: all backups last 14 days, one per ISO week for 180 days, minimum 5 kept
- Daily **`store-preorders:close-expired`** — 00:15; close open store preorders whose closing date is before today (same close path as the UI, Shopify qty 0 first)
- Daily **`store-preorders:refresh-missing-photos`** — 07:00; attach real Plamod photos for open and closed offers (rejects “No image” placeholders), Shopify-push or clear fake media, recrawl Plamod PDP when a real pick-list shot is also missing
- Daily **`plamod:instock-sync`** — 05:00; queue the same PLAMOD in-stock restock refresh as **Refresh from PLAMOD** (skips if a refresh is already running)
- Daily **`plamod:preorders-sync`** — 06:00; queue the same pick-list refresh as **Refresh from Plamod** (skips if a refresh is already running)
- **Off-site push** — daily ~04:00 to consolidation droplet (`local-llm` scripts; see `pricing-tool-offsite-backup.md`)
- Env: `DB_BACKUP_SCHEDULE_*`, `DB_BACKUP_RETENTION_*` (see `.env.example`)
- Preview purge: `php artisan db:backup:purge --dry-run`

---

## Flush products table

**Confirm dialog** warns **Deletes ALL products** → issues **`DELETE /api/v1/products`** routed to **`ProductMaintenanceController`** → **`flushAll()`** catastrophic wipe for greenfield resets.

⚠ Extremely dangerous—coordinate backups first.

---

## Refresh latest product costs

Non-destructive recompute (**`POST /api/v1/maintenance/refresh-latest-costs`**) invoking **`ProductLatestCostCacheService::recomputeAll`** — summarizes **`matched`** / **`updated`**.

Confirmation copy clarifies breadth (recalculates cache columns for SKU universe).

---

## Clear stale latest arrival flags

**Confirm dialog** → **`POST /api/v1/maintenance/clear-stale-latest-arrival`**.

Removes **`products.latest_arrival`** (sets `false`) for products on purchase orders whose **`received_date`** is more than **4 weeks** ago (or **`created_at`** when **`received_date`** is null), **except** products that also appear on any PO **within** the last 4 weeks (same date rules)—those keep the flag. **`published_on_shopify`** is not changed.

After the local clear, calls Shopify **`tagsRemove`** only for products whose **`latest_arrival`** actually changed from **true** to **false** (mirrored SKU → `shopify_product_variants.product_gid`), removing **only** the **`latest arrival`** tag (`ProductExportService::LATEST_ARRIVAL_TAG`). Does not change status, other tags, inventory, or prices. Requires **`write_products`** OAuth scope when at least one changed product has a mirror GID.

Use before marking a new PO’s products as latest arrival (same action is available on the PO workflow row as **Clear old latest**).

Response: **`purchase_orders_matched`**, **`products_cleared`**, **`cutoff_date`**, **`shopify_tags_removed`**, **`shopify_skipped_no_gid`**, **`shopify_tag_removals_failed`**.

---

## Product type tooling

Two distinct maintenance passes with explicit confirm copy differences:

| Button                                      | Endpoint                                    | Intent                                                                               |
| ------------------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------ |
| **Backfill missing types only**             | **`POST /api/v1/products/backfill-types`**  | Fills empty types inferred from textual description heuristics (see service)         |
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
