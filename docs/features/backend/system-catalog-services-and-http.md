# System catalog: HTTP, jobs, services, and middleware

**UI walkthroughs** live in **`docs/features/`** (start at [`docs/features/README.md`](../README.md)). This file is the **dense backend catalog** (routes, middleware, services).

This document is an **inventory of current behavior** in the pricing-tool codebase. It complements (and occasionally refines) the feature specs under `docs/requirements/`. When in doubt after refactors, **trust the tests and code**—this file should be updated alongside changes.

---

## How to maintain this doc

| Area              | Authoritative specs                                  | Executable truth                   |
| ----------------- | ---------------------------------------------------- | ---------------------------------- |
| User-facing flows | `docs/requirements/*.md`, `docs/project-overview.md` | Feature / E2E tests, Vue pages     |
| HTTP surface      | Summarized below                                     | `routes/api.php`, `routes/web.php` |
| Domain rules      | This file + specs                                    | `app/Services/**`, `app/DAL/**`    |

---

## Application shape

### SPA + JSON API

- **Browser UI**: Laravel serves `resources/views/app.blade.php` for `/` and all non-API paths (`web.php` catch-all `{any}`), load controlled by **`resources/js/router.ts`** (Vue Router, HTML5 history).
- **REST API**: Most JSON business routes are under **`/api/v1/...`** (`routes/api.php` group prefix `v1`; Laravel mounts `api.php` under `/api` by default). Selected **ingress endpoints** omit `v1` (for example Shopify webhooks—see webhook row below).

### Middleware stack (`bootstrap/app.php`)

Global middleware appended for every request:

| Middleware                         | Behavior                                                                                                                                                                                                                                                                                 |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `trustProxies(at: '*')`            | Proxies/tunnels report HTTPS so **signed URLs** validate behind reverse proxies and Cloudflare tunnel.                                                                                                                                                                                   |
| `ShopifyImagesOnlyMiddleware`      | If `config('app.shopify_images_only')` is **true**, every path except `/shopify-images/...` and **`POST /api/webhooks/shopify`** (optional `/index.php/` prefix normalization) returns **404**. Intended for an **images-only** PHP worker/process that shares routing with ERP ingress. |
| `ExternalAccessPasswordMiddleware` | When external access mode is configured, applies **loopback bypass**, **employee path allow-lists**, or **password gate**. **`POST /api/webhooks/shopify`** bypasses the cookie/password gate (**HMAC** verification in **`ShopifyWebhookIngressService`**). See § External access gate. |
| `NoCacheHtmlMiddleware`            | Prevents stale caching of SPA HTML shell (fresh Vite manifest / bundle).                                                                                                                                                                                                                 |

**Cookie note:** `ExternalAccessAuthService::COOKIE_NAME` is **excluded from encryption** so validation works without Laravel session on lightweight routes.

---

## Roles and SPA routing (`resources/js`)

### Access role propagation

`resources/js/lib/accessRole.ts` reads `<meta name="external-access-role" content="...">` injected by the server:

| Role returned  | Effect                                                                                                                          |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| **`admin`**    | Full navigation to all SPA routes listed in `router.ts`. Default when meta absent or unrecognized.                              |
| **`employee`** | **Router guard** sends every navigation to **`/employee/inventory-count`** except that path itself + default redirect from `/`. |

Employees also pass through API allow-lists enforced in `ExternalAccessPasswordMiddleware` (not only SPA).

---

## Frontend routes (`resources/js/router.ts`)

| Path                            | Page                                                                    | Audience |
| ------------------------------- | ----------------------------------------------------------------------- | -------- |
| `/`                             | Redirect: employees → `/employee/inventory-count`, others → `/products` | Both     |
| `/import`                       | Redirect → `/products#hash#import`                                      | Admin    |
| `/employee/inventory-count`     | Employee inventory scanning session UI                                  | Employee |
| `/products`                     | Product catalog workflows (imports/exports/sync/PDP drawer…)            | Admin    |
| `/products/taxonomy`            | Canonical taxonomy research and verification queue                      | Admin    |
| `/purchase-orders`              | PO list                                                                 | Admin    |
| `/purchase-orders/:id`          | PO detail (classic, default)                                            | Admin    |
| `/purchase-orders/:id/beta`     | PO detail beta workspace (opt-in)                                       | Admin    |
| `/inventory-check`              | Inventory check sessions list / import UX                               | Admin    |
| `/inventory-check/:id`          | Session detail                                                          | Admin    |
| `/price-research`               | Competitor quotes table                                                 | Admin    |
| `/price-research/reports`       | Quote issue reports                                                     | Admin    |
| `/price-research/runs/:id/logs` | Run logs for a research batch                                           | Admin    |
| `/sync-progress`                | Laravel job-batch progress (`/api/v1/job-batches/...`)                  | Admin    |
| `/store-events`                 | In-store community event CRUD                                           | Admin    |
| `/marketing-notes`              | Marketing notes (sheet Other column)                                    | Admin    |
| `/tcg-events`                   | TCG event listing + refresh trigger                                     | Admin    |
| `/maintenance`                  | Notes, backups, tunnel, external-access settings, refresh costs…        | Admin    |

---

## External access gate (`ExternalAccessPasswordMiddleware`)

Preconditions (conceptual):

1. Not in **Shopify-images-only** mode → gate skipped entirely for that deployment.
2. Else if request is **`POST /api/webhooks/shopify`** (normalized `/index.php/` prefix stripped) → **bypass** cookie/password (**HMAC** verification only; see **`ShopifyWebhookIngressService`**).
3. Else if **hostname is pure loopback** (`localhost`, `127.x.x.x`, `[::1]`, `::1`, with optional port) → **`external_access_role` = admin**, no cookie required.
4. Else **external access must be enabled** in settings; if disabled → **404** (app hidden).
5. External **password must be configured** or → **404**.

Then:

| Condition                                                     | Behavior                                                                                                                                                                                              |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Valid signing cookie resolves to a role                       | Request proceeds; **`external_access_role`** attribute set on request. Employee role denied non-allowed paths (**404** for web, **`{"ok":false,"error":"not_found"}` 404 JSON** for disallowed APIs). |
| Employee + API                                                | Allowed only **`/api/v1/inventory-check/employee/**`** and **`GET /api/v1/product-assets/{id}/view`\*\* (images for scan cards).                                                                      |
| Employee + SPA                                                | Allowed `/build/*`, `/external-login`, `/favicon.ico`, `/up`, `/`, `/employee`, `/employee/inventory-count`.                                                                                          |
| **`POST /api/webhooks/shopify` without cookie**               | Handled upstream of password requirement; validates **Shopify HMAC** and returns **`200`** or **`401`**. Logs rows in **`shopify_webhook_logs`**. **`503`** if `SHOPIFY_WEBHOOK_SECRET` is missing.   |
| Unauthenticated `/api/**` (excluding Shopify webhook ingress) | **401** JSON `external_auth_required`.                                                                                                                                                                |
| Unauthenticated browser                                       | Redirect **`/external-login?next=`** preserved request URI.                                                                                                                                           |
| `/external-login`                                             | Explicitly excluded from gate loop (`web.php`).                                                                                                                                                       |

### Host detection detail

Effective host prefers **`X-Forwarded-Host`** first comma-separated segment, else `Request::getHost()`, lowercased—so proxies must send accurate forwarded host for loopback vs external classification.

---

## Public web routes (`routes/web.php`)

| Route                                                       | Purpose                                                                                              |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `GET/POST /external-login`                                  | Password gate minimal login; POST **without CSRF middleware**.                                       |
| `GET /shopify-images/{id}`                                  | Signed/expiring **Shopify ingest** URLs (query-variant).                                             |
| `GET /shopify-images/{id}/{expires}/{signature}`            | Path-signed variant (**Shopify CSV may strip queries**).                                             |
| `GET /shopify-images/{id}/{expires}/{signature}/{filename}` | Same + human-readable trailing filename segment. Cookie/session/CSRF disabled for stability/caching. |
| `GET /`, `/{any}`                                           | SPA blade shell.                                                                                     |

---

## Shopify ERP webhook ingress (`POST /api/webhooks/shopify`)

| Method | Path                        | Purpose                                                                                                                                                                                                                                                                                                                                                                                                                               |
| ------ | --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | **`/api/webhooks/shopify`** | Declared **before** `Route::prefix('v1')` in `routes/api.php`. Validates **Shopify webhook HMAC** (`SHOPIFY_WEBHOOK_SECRET`), persists **`shopify_webhook_logs`**, fires **`ShopifyWebhookReceived`** (Phase 2 domain hooks). Bypasses **`ExternalAccessPasswordMiddleware`** cookie requirement and survives **`ShopifyImagesOnlyMiddleware`**. **Production target URL:** `https://ovs.centredentairevsl.com/api/webhooks/shopify`. |

---

## API routes (`routes/api.php`, prefix `/api/v1`)

Below, **verbs** reflect Laravel router methods. **`{id}` on products** uses **UUID**. **`purchase-orders/{id}`** uses **UUID**. **Numeric ids** noted where Laravel `whereNumber` applies.

### Products — query & CRUD

| Method | Path                       | Purpose                                                                                               |
| ------ | -------------------------- | ----------------------------------------------------------------------------------------------------- |
| GET    | `/products`                | Paginated/filtered product listing (supports search, PDP completeness filters—see Products UI/specs). |
| POST   | `/products`                | Create product.                                                                                       |
| PATCH  | `/products/{id}`           | Partial update (`ProductsController::update`).                                                        |
| GET    | `/products/filter-options` | Facets for the Products grid: distinct taxonomy/vendor values, `empty_fields`, all POs for the PO dropdown, and labeled enum options (`missing_info`, flags, shipment, ready, archived, published, `po_novelty`). |
| GET    | `/products/saved-views` | Shared Products list views (filters, sort, visible columns). |
| POST   | `/products/saved-views` | Create or overwrite a shared view by name (`name`, `snapshot`, `visible_columns`). **201** when created, **200** when updated. |
| PUT    | `/products/saved-views/{id}` | Update a shared view. **404** if missing; **422** if the name is already used. |
| DELETE | `/products/saved-views/{id}` | Delete a shared view. **204**. **404** if missing. |

`GET /products` exposes computed **`not_arrived`** and **`reorder`** fields and accepts `not_arrived`, `not_arrived_min`, `not_arrived_include_draft_orders`, and `sort_by=not_arrived`. `ProductNotArrivedQtyService` / `ProductInboundOpenPoQtySql` define **Not arrived** as the sum of positive PO-line `qty_ordered` until the parent PO has `fully_on_shelves_date`; received-but-not-shelved quantities remain included. Products/report/replenishment defaults include draft POs, while PO-detail metrics and PLAMOD restock exclude drafts.

### Products — canonical taxonomy verification

| Method | Path                                            | Purpose                                                                                                                                     |
| ------ | ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/products/taxonomy/verifications`              | Latest completed-run review queue; combines status/search, canonical field arrays, confidence, archive state, and differences-only filters. |
| GET    | `/products/taxonomy/filter-options`             | Distinct proposed canonical values from the latest completed research run.                                                                  |
| GET    | `/products/taxonomy/summary`                    | Latest completed-run totals by proposal/verification state and low confidence.                                                              |
| PATCH  | `/products/taxonomy/verifications/{id}/approve` | Approve proposed values or save an operator override transactionally.                                                                       |
| POST   | `/products/taxonomy/verifications/bulk-approve` | Confirm, then apply matching high-confidence proposals. Skips test SKUs and kits without a manufacturer.                                    |
| POST   | `/products/taxonomy/verifications/bulk-update`  | Confirm, then override selected proposed rows with operator-supplied canonical values. Skips test SKUs.                                     |
| GET    | `/products/taxonomy/export`                     | Confirmation CSV of the current review filter set.                                                                                          |
| POST   | `/products/taxonomy/research`                   | Queue all-record canonical taxonomy research (CLI/jobs; not exposed in taxonomy review UI).                                                 |
| POST   | `/products/taxonomy/verifications/research`   | Re-derive taxonomy for selected verification rows; optional `fields[]` (default all); sets status to proposed without ERP write.           |
| GET    | `/products/taxonomy/research/{id}`              | Research-run progress and counts.                                                                                                           |

Canonical fields are additive to legacy `main_type`, `type`, and `brand`. Existing Shopify tags,
collections, and navigation remain on the legacy compatibility path until an explicitly approved cutover.

### Products — barcode, availability, editorial flags

| Method | Path                                                       | Purpose                                                                                                                                                                                 |
| ------ | ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PATCH  | `/products/{id}/barcode`                                   | Update barcode.                                                                                                                                                                         |
| PATCH  | `/products/{id}/filled`                                    | “Filled” workflow flag on product record.                                                                                                                                               |
| PATCH  | `/products/{id}/available`                                 | **`available_qty`** (non-negative; must be ≥ current **`hold_qty`**).                                                                                                                   |
| PATCH  | `/products/{id}/hold`                                      | **`hold_qty`** (non-negative; must be ≤ **`available_qty`**).                                                                                                                           |
| PATCH  | `/products/{id}/maintain`                                  | Maintenance flag semantics (see migration/model).                                                                                                                                       |
| PATCH  | `/products/{id}/ready`                                     | Readiness workflow flag.                                                                                                                                                                |
| PATCH  | `/products/{id}/latest-arrival`                            | Latest arrival bookkeeping for PO/receiving workflows.                                                                                                                                  |
| PATCH  | `/products/{id}/critical`                                  | Critical product flag (`is_critical`, default false).                                                                                                                                   |
| PATCH  | `/products/{id}/urgent`                                    | Urgent product flag (`is_urgent`, default false).                                                                                                                                       |
| PATCH  | `/products/{id}/discontinue`                               | Discontinue product flag (`is_discontinued`, default false).                                                                                                                            |
| PATCH  | `/products/{id}/hazardous-shipment`                        | Hazardous shipment flag (`is_hazardous_shipment`, default false).                                                                                                                       |
| PATCH  | `/products/{id}/shipment-method`                           | Shipment method (`shipment_method`: `air`, `sea`, or null).                                                                                                                             |
| POST   | `/purchase-orders/{id}/workflow-actions/prepare-inventory` | Validates PO **qty received**; skips Shopify if mirror fresh (default 1h). If stale, returns confirmation payload unless body **`pull_shopify: true`** (PO-SKU inventory refresh only). |
| GET    | `/products`                                                | List supports `product_flags[]`: `urgent`, `critical`, `discontinued`, `hazardous_shipment` (multi-select OR); `shipment_methods[]`: `air`, `sea` (multi-select OR); `sort_by=is_urgent`. |
| PUT    | `/products/{id}/selling-price`                             | Upsert **`product_selling_prices`** row (Shopify variant price drives exports); appends **`product_selling_price_history`** with **`source: manual`** when price changes.               |
| GET    | `/products/{id}/selling-price-history`                     | Append-only selling price change log for the product (optional **`purchase_order_uuid`** when change came from PO Set/review).                                                          |

### Products — Shopify / replenishment helpers

| Method | Path                      | Purpose                                                                                                                                                                                                                          |
| ------ | ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/products/{id}/po-lines` | Outstanding / related PO lines for product context UI.                                                                                                                                                                           |
| GET    | `/products/{id}/demand`   | Product demand detail for the products grid dialog: 4/12-week Shopify + assumed rollups, 365-day weekly rollups, and recent Shopify line items (`lines_page`, `lines_per_page`). Cancelled / voided Shopify orders are excluded. |

### PDP / Plamod / “product info” bundle

Two **different orchestrations** deliberately exist:

| Method | Path                                          | Job                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Expectations |
| ------ | --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| POST   | `/products/{id}/plamod/sync`                  | Dispatches **`SyncPlamodAssetsJob`** (**202**) with **`attemptPlamodAssets` default false** (constructor default). Executes **`PlamodAssetSyncService`** only → for **non‑Plamod vendors**: best-effort **HLJ-only** shortcut; for **`vendor === Plamod`**: ZIP asset pipeline runs without the manual “force asset attempt” flag.                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| POST   | `/products/{id}/product-info/sync`            | Dispatches **`SyncProductInfoJob`** (**202**) with **`attemptPlamodAssets = true`** always. Implements the **manual “Get product info”**: **attempts Plamod ZIP/assets even when `vendor`≠Plamod**, then **`BandaiContentSyncService`**, then competitor PDP scrapes (**GundamPlanet / Newtype / GundamHangar**) with isolated try/catch (failures appended to optional batch debug log). **HLJ enrichment** flows through **`PlamodAssetSyncService`** internals when ZIP path exercised; **`SyncProductInfoJob` skips the HLJ-first `else` branch when `attemptPlamodAssets` is true**—so HLJ in that mode is reached via **`PlamodAssetSyncService` / HLJ injections** documented in that service, not duplicated in the outer `else` block. Logs `product_info.sync.completed`. |
| GET    | `/products/{id}/product-info`                 | Aggregated PDP JSON for drawers (preferred over older Plamod-only shape where UI migrated).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| GET    | `/products/{id}/plamod`                       | Legacy PDP snapshot (carousel, external content + assets)—still wired for compat/tests.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| PATCH  | `/products/{id}/preferred-description-source` | Sets **`preferred_description_source`** (`hlj`, `plamod`, or competitor `source` keys on `product_external_contents`) influencing **Shopify content export body**.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |

### External assets ordering & Shopify toggles

| Method | Path                                  | Purpose                                                                                                                          |
| ------ | ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| PUT    | `/products/{id}/plamod/assets/order`  | Re-order **Plamod-sourced** image assets (`sort_order`).                                                                         |
| PUT    | `/products/{id}/assets/order`         | Re-order **all** relevant image assets (includes manual uploads / mixed ordering—see UI).                                        |
| POST   | `/products/{id}/assets/manual-upload` | Multipart upload of **`manual_upload`** assets; persists file + checksum + Shopify-enabled default. Response `data` includes **`created`**, **`asset_ids`**, and full **`assets`** payloads (so the Info drawer can append without `GET /product-info`). |
| PATCH  | `/products/{id}/assets/shopify-enabled` | Bulk toggle Shopify export for **`ids`** on that product (`shopify_enabled` + `ids: int[]`). Used by hide-source and exact-duplicate disable. |

| Method | Path                                   | Purpose                                                                                                                                                                                                                      |
| ------ | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/product-assets/{id}/download`        | Attachment download (numeric **`id`**).                                                                                                                                                                                      |
| GET    | `/product-assets/{id}/view`            | Inline view (numeric **`id`**).                                                                                                                                                                                              |
| GET    | `/product-assets/{id}/thumb`           | Inline thumbnail (max width **320px** JPEG, generated on first request and cached under `product-external-assets/thumbs/{id}.jpg`; falls back to full file when GD unavailable). **`Cache-Control: public, max-age=86400`**. |
| PATCH  | `/product-assets/{id}/shopify-enabled` | Toggle whether asset participates in Shopify image export (**`shopifyImageAssets`** relationship). Info drawer On/Off is optimistic and does not wait for the follow-up **`PUT /products/{id}/assets/order`**.                                                                                                                           |
| DELETE | `/product-assets/{id}`                 | **Only** **`source === manual_upload`**: deletes DB row + underlying storage file (`ProductManualImageDeleteService`). Other sources → **`ManualUploadDeletionDeniedException`** / mapped HTTP error (see tests).            |

### Bulk PDP sync via job batches

| Method | Path                          | Purpose                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ------ | ----------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/products/sync-missing-info` | Runs **`ProductMissingInfoSyncService`**: validates filter payload (`types`, `vendors`, **`missing`** subset of **`pdp_description`** / **`pdp_images`**, optional `dry_run`). Builds Laravel **`Bus::batch`** of **`SyncPlamodAssetsJob`** (**queue `pdp_sync`**) with **`allowFailures()`**. Each job executes **`PlamodAssetSyncService::syncByProductUuid`** only—**does not run **`SyncProductInfoJob`**’s **`BandaiContentSyncService`** or GundamPlanet / Newtype / GundamHangar competitor PDP passes** (`$attemptPlamodAssets` toggles ZIP vs HLJ-shortcut branch inside **`PlamodAssetSyncService`**, vendor-dependent). Insert **`job_batch_items`** rows for UI progress on **`EloquentJobBatchItemRepository::insertQueued`**. Response: `queued` count / `dry_run` / **`batch_id`**. |
| GET    | `/job-batches`                | Recent batches for Sync Progress UI.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| GET    | `/job-batches/{id}`           | Status/progress.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| GET    | `/job-batches/{id}/items`     | Per-job item states for debugging/UI.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| POST   | `/job-batches/{id}/cancel`    | Cooperative cancel.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| POST   | `/job-batches/{id}/resume`    | Resume stalled/cancel-aware batches (**see `JobBatchResumeService`** for safety rules).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |

### Product imports (`multipart` / CSV)

| Method | Path                                      | Purpose                                                                                                                                                                                                                  |
| ------ | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| POST   | `/products/import`                        | Bulk product CSV import (**conflicts, duplicate SKU policy** enforced in `ProductImportService`—see exceptions + tests).                                                                                                 |
| POST   | `/products/import-inventory`              | Shopify-style inventory CSV (**Variant SKU**, **Variant Inventory Qty**) → **`products.available_qty`**; responds with unmatched SKUs; **safety backup** before apply (requirements: `shopify-export-and-inventory.md`). |
| POST   | `/products/import-inventory-qty-override` | Operational CSV to **overwrite** available quantities per rules in request/service tests.                                                                                                                                |
| POST   | `/products/import-handles`                | Shopify **`Variant SKU` + `Handle`** import; overwrites **`products.handle`** when Handle non-empty; reports missing SKUs/blanks (`handle-and-shopify-content-export.md`).                                               |
| POST   | `/products/import-inventory-check`        | **Inventory-check session** import (**not** necessarily same semantics as Shopify export—requirements: `inventory-check.md`).                                                                                            |

### Product exports (CSV)

| Method | Path                                     | Purpose                                                                                                                                                                          |
| ------ | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/products/export`                       | Canonical **Shopify CSV** rows; **omit products without selling price** (see `ProductExportService` + requirements). **`Published`** column reflects **`published_on_shopify`**. |
| GET    | `/products/export/filtered`              | UTF‑8 BOM **catalog CSV** for all products matching **`GET /products` list filters** (same query params; no pagination). Columns from `ProductExportService::catalogHeader()`.   |
| POST   | `/products/export/selected`              | Same Shopify format but constrained to submitted UUID subset.                                                                                                                    |
| GET    | `/products/export/missing-barcode`       | Operational export of barcode gaps.                                                                                                                                              |
| GET    | `/products/export/missing-selling-price` | Operational export before pricing work.                                                                                                                                          |
| GET    | `/products/export/barcoded`              | Inventory-friendly UTF‑8 BOM CSV for barcoded SKUs (**Handle first** ordering for inventory check export—requirements).                                                          |
| GET    | `/products/replenishment/preview`        | Read-only replenishment projections; inbound/not-arrived quantity remains counted until the PO is fully on shelves.                                                              |
| GET    | `/products/replenishment/export`         | Replenishment CSV with the same shelf-based inbound/not-arrived quantity.                                                                                                        |

### Product maintenance & crawling

| Method | Path                                  | Purpose                                                                                                                                                            |
| ------ | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| POST   | `/products/recrawl/selected`          | Queues `RecrawlSelectedProductJob` for selected image sources and/or individual price-research site keys (`config/price_research.php`). Legacy `competitor_price_research` still crawls every price site. |
| POST   | `/products/shopify-push/preview`      | Preview bulk ERP → Shopify push for selected product UUIDs + `push_options` field matrix (`ProductsBulkPushShopifyPreviewService`). Local ERP + Shopify mirror only — no live Admin SKU search. |
| POST   | `/products/shopify-push/selected`     | Queues `PushSelectedProductToShopifyJob` batch (`push_selected_products_shopify`); **202** + `batch_id`.                                                           |
| POST   | `/products/backfill-types`            | Backfills **type** derivation gaps using `ProductTypeBackfill`/related orchestration.                                                                              |
| POST   | `/products/recompute-types`           | Runs smarter/type rules across catalog (`ProductTypeRecomputeService`).                                                                                            |
| POST   | `/products/bulk-delete`               | Destructive multi-delete guarded by validations + tests (`ProductBulkDeleteService`).                                                                              |
| POST   | `/products/bulk-archive`              | Sets **`archived_at`** timestamps (scoped queries like `scopeNotArchived` exclude archived products).                                                              |
| POST   | `/products/bulk-update`               | Shared bulk PATCH payload for selected rows (published flags, etc.—see controller request).                                                                        |
| POST   | `/products/bulk/plamod-assets/rename` | Queues **`RenameSelectedProductAssetsJob`** for asset filename normalization rules.                                                                                |
| DELETE | `/products` (**no id**)               | **`ProductMaintenanceController::flush`**: wipes product-related domain data (**extremely destructive**—maintenance tooling only—see `ProductMaintenanceService`). |

### Shopify — quick tunnel & content exports

| Method | Path                          | Purpose                                                               |
| ------ | ----------------------------- | --------------------------------------------------------------------- |
| GET    | `/shopify/image-tunnel`       | Current Cloudflare tunnel / trycloudflare status JSON.                |
| POST   | `/shopify/image-tunnel/start` | Start tunnel subprocess management via `AppCloudflaredTunnelService`. |
| POST   | `/shopify/image-tunnel/stop`  | Stop tunnel process.                                                  |

| Method | Path                                                     | Purpose                                                                                                                                                                                                                                                                               |
| ------ | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/products/exports/shopify-content/prepare`              | Builds **Shopify content CSV**: body HTML + variant columns from **`ProductExportService::shopifyHeader()`** (+ optional signed image URLs using tunnel base URL). **`includeInventory=true`**. Supports optional **`product_uuid[]` filter** (`ShopifyContentExportPrepareRequest`). |
| POST   | `/products/exports/shopify-content-no-inventory/prepare` | Same **`ShopifyContentExportService::prepareNoInventory`** using header variant without inventory columns.                                                                                                                                                                            |
| GET    | `/products/exports/shopify-content/download/{exportId}`  | Streams CSV generated server-side (`exports/shopify_content/{uuid}.csv` disk layout).                                                                                                                                                                                                 |

**Who is included (`listForShopifyContentExport`):**

- `notArchived()`
- **`whereHas`** `selling_price` relation with **non-null, non-empty** `selling_price`  
  (stricter than “row exists”—empty string excludes product).

**Skipped during prepare:**

- Missing handle after **`ensureHandle`** (generates + **persists** new handle via `shopifyHandleForProduct` scratch map to avoid collisions).
- Duplicate handle collisions across CSV build after generation.

### Purchase orders

| Method | Path                                         | Purpose                                                                                                                                                                                                                                                                                                                                                             |
| ------ | -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/purchase-orders/import/preview`            | Parses upload without DB write; returns HKD/CAD line preview for Dspiae/Stedi/Other/multi PM broker invoices (`PurchaseOrderImportService::preview`).                                                                                                                                                                                                               |
| POST   | `/purchase-orders/import`                    | Parses vendor-specific PDF/HTML/CSV/XLSX blobs into PO + lines (`PurchaseOrderImportService`—heavy edge-case coverage in `tests/Feature/Api/V1/PurchaseOrder*`). Supports append/re-import behaviors per tests. New **Plamod** POs default **`shipment_method=air`** when unset.                                                                                                                                                     |
| POST   | `/purchase-orders/combined-payments/preview` | Validates two or more same-currency foreign POs and previews one CAD payment allocated across their vendor product totals and, optionally, vendor freight totals. Accepts optional combined `product_paid_cad` + `shipping_paid_cad` pools or exact per-PO CAD `allocations[]`; all amounts must reconcile to `total_paid_cad`.                                     |
| POST   | `/purchase-orders/combined-payments`         | Persists the combined-payment header and per-PO snapshots, updates each PO's suggested or exact CAD product/shipping allocation, and converts line CAD unit costs using each PO's product FX without changing original vendor-currency values.                                                                                                                      |
| GET    | `/purchase-orders`                           | Indexed list. Filters: `vendors[]`, `statuses[]`. Sort: `sort_by` (`id`, `status`, `shipment`, `created`, `ordered`, `estimated_arrival`, `received`, `on_shelves`, `vendor`, `items`, `product_total`, `shipping_total`, `surcharge_total`, `total`, plus internal `filter`) and `sort_dir` (`asc`\|`desc`). Default `ordered desc`.                               |
| GET    | `/purchase-orders/filter-options`            | Dropdown facets.                                                                                                                                                                                                                                                                                                                                                    |
| POST   | `/shipment-tracking/resolutions`             | Bulk status/dispatch endpoint for credential-free shipment tracking. Normalizes and caches one row per tracking number, queues `ResolveShipmentTrackingJob` on the isolated `shipment_tracking` worker without waiting for public providers, and returns `queued`, `resolving`, `resolved`, `not_found`, or `failed` plus the verified provider URL when available. |
| GET    | `/purchase-orders/{id}`                      | Detailed PO projection including FX-derived CAD unit economics where imported (`PurchaseOrderResource`).                                                                                                                                                                                                                                                            |
| PATCH  | `/purchase-orders/{id}`                      | Header updates (dates, totals, **`shipment_method`** (`air` \| `sea` \| null), **`shipment_tracking_numbers`** array (up to 40 strings, max 255 each), checklist container, **`is_done`**, notes…).                                                                                                                                                                 |
| DELETE | `/purchase-orders/{id}`                      | Controlled delete with referential safeguards (`PurchaseOrderDeleteService`).                                                                                                                                                                                                                                                                                       |

| Draft workflow | Purpose |
| -------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| POST | `/purchase-orders/drafts/create-from-products` | Convert selected products into PO draft lines (`PurchaseOrderDraftService`). |
| POST | `/purchase-orders/{id}/draft-products` | Append SKU lines referencing catalog. |
| GET | `/purchase-orders/{id}/draft-lines-export` | Vendor order CSV: SKU, name, qty, always CAD product cost columns; Stedi/Dspiae add HKD columns; FX fallback from latest PO with same vendor currency. |

| Line updates | Purpose |
| ------------ | ----------------------------- | -------------------------------------------------------------------------------------------- |
| PATCH | `/purchase-orders/{id}/items` | Bulk update selected line ids, including the confirmed receiving exception that copies **`qty_shipped` → `qty_received`** for selected/all PO lines (see bulk request). |
| POST | `/purchase-orders/{id}/water-decals/preview` | Preview promoting selected PO lines to water decals (`item_ids`, optional `proposed[]` SKU overrides). |
| POST | `/purchase-orders/{id}/water-decals/apply` | Apply promote/merge rows from preview (`rows[]` with SKU, description, vendor, type, `confirm_merge`). |
| PATCH | `/purchase-order-items/{id}` | Numeric line id PATCH for granular edits, including **`qty_damaged`**; damaged cannot exceed received and receipt quantities are locked when PO-linked inventory lots exist. |

| Applicators | Behavior summary |
| ----------- | --------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST | `/purchase-orders/{id}/apply-received-to-available` | Requires **`qty_received` entered on every line** (`0` is valid; **`null`** is not) and **`qty_damaged <= qty_received`**; sums **`max(0, qty_received - qty_damaged)`** per distinct **`product_id`**, then increments **`products.available_qty`** transactionally (`PurchaseOrderApplyReceivedToAvailableService`). |
| POST | `/purchase-orders/{id}/apply-inventory-check` | **Destructive-ish reset**: deletes inventory movements/lots linked to PO line ids, clears **`qty_received`**, aggregates inventory-check rows by SKU, overwrites **`qty_received`**, then **`ProductLatestCostCacheService::recomputeForSkus`** for affected SKU set (`PurchaseOrderApplyInventoryCheckService`). Returns warnings like SKUs appearing on check lines but not PO. |

| Checklist store | Meaning |
| --------------- | ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PATCH | `/purchase-orders/{id}/workflow-checklist` | JSON checklist blob persisted to **`purchase_orders.workflow_checklist_json`** validated by `PurchaseOrderWorkflowChecklistUpdateRequest` + service for shape constraints. |

**Model fields (conceptual anchors):**

- Currency + FX (**`vendor_currency_code`**, **`fx_rate_to_cad`**) imported with PO.
- Receipt dates vs shelf dates drive landed-cost denominator selection in cache service.

### Inventory check (admin + employee)

| Admin session CRUD-ish | Purpose |
| ---------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| POST | `/products/import-inventory-check` | Creates **`inventory_check` + inventory_check_items** from counted CSV (**matching rules**: Handle then `(SKU,Vendor)`—requirements). Optional multipart **`notes`** stores session label on **`inventory_check.notes`**. |
| GET | `/inventory-check` | List historical sessions (`InventoryCheckQueryService`/repository-backed). |
| PATCH | `/inventory-check/{uuid}` | Update session metadata (**`notes`**, nullable string max 2000). |
| GET | `/inventory-check/{uuid}` | Session detail projection. |
| GET | `/inventory-check/{uuid}/download` | Original CSV blob download if stored (`uploaded_file_path`). |
| DELETE | `/inventory-check/{uuid}` | Session delete (**must match confirm-delete-actions rule at UI layer**—API still should enforce authorization if added later). |
| PATCH | `/inventory-check/{uuid}/items/{lineId}` | Line-level tweaks when reviewing CSV rows pre-apply. |
| POST | `/inventory-check/{uuid}/items/{lineId}/assign-product` | Resolves unmatched review rows by assigning an existing product UUID; row snapshots/status/errors are refreshed by `EmployeeInventoryCountService::assignLineToProduct`. |

| Employee scan flows | Meaning |
| ------------------- | ------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST | `/inventory-check/employee/sessions` | Creates **`inventory_check`** with **`source='employee_scan'`**, `workflow_state='draft'`; attributes creator role (`EmployeeInventoryCountService::createSession`). |
| GET | `/inventory-check/employee/sessions/{uuid}` | Session payload augmented with thumbnails via **`resolveImageUrlsByProductId`** (hits first Plamod image asset rule in service—see implementation). |
| POST | **`.../scan`** body `{ barcode }` | Looks up **`Product`** by barcode (**`notArchived()`** constraint). Dedup/issue rows on unknown barcodes behave per transactional rules in **`EmployeeInventoryCountService::scanBarcode`** (increment quantity vs create issue stubs—read method for branching). |
| PATCH | **`.../lines/{lineId}`** | Update counted **`quantity`** and/or **`product_name`** (**422** requires at least one field). |
| DELETE | **`.../lines/{lineId}`** | Remove line mid-session (`removeLine`). |
| POST | **`.../flag-issue`** | Annotate barcode discrepancy without product match (`flagBarcodeIssue`). |

| Unified apply endpoint | Consumers |
| ---------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST | **`/inventory-check/{uuid}/apply`** | Delegates **admin + employee modes** via **`EmployeeInventoryCountService::applySessionQuantities`**: knobs `apply_quantity`, `apply_name`, `apply_quantity_mode` (`overwrite` | `increment`), optional `line_item_ids`. Updates canonical **`products.available_qty`** + optional description updates governed by **`apply_name`**/`apply_quantity`. Uses **`ConflictHttpException`** when session forbidden to apply (**see guards** inside service). |

### Maintenance APIs

| Method | Path                                                       | Purpose                                                                                                              |
| ------ | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| GET    | `/maintenance/notes`                                       | Freeform maintenance bulletin text.                                                                                  |
| PUT    | `/maintenance/notes`                                       | Upserts maintenance note markdown/plain text blob.                                                                   |
| GET    | `/maintenance/special-order-customer-message-template` | Special order DM template (`maintenance_notes` key `special_order_customer_message`).                              |
| PUT    | `/maintenance/special-order-customer-message-template` | Save template `{ body }` or reset `{ reset: true }`. Placeholders: `{product_name}`, `{price}`, `{deposit_percent}`. |
| GET    | `/maintenance/special-order-pricing-caps` | Special order pricing caps (`maintenance_notes` key `special_order_pricing_caps`). Defaults: $50 merchandiser commission, $150 OPV margin. |
| PUT    | `/maintenance/special-order-pricing-caps` | Save caps `{ merchandiser_commission_cap_cad, opv_margin_cap_cad }` or reset `{ reset: true }`. |
| GET    | `/maintenance/opv-catalog-pricing` | OPV catalog margin + default store-preorder deposit (`maintenance_notes` key `opv_catalog_pricing`). Defaults: 1.50×, 20%. |
| PUT    | `/maintenance/opv-catalog-pricing` | Save `{ price_multiplier, default_deposit_percent }` or reset `{ reset: true }`. |

| Database backups (`docs/requirements/maintenance-db-backups.md`) | Purpose |
| ---------------------------------------------------------------- | --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| GET | `/maintenance/db-backups` | List metadata rows from `database_backups` (+ size). |
| POST | `/maintenance/db-backups` | Runs logical dump via **`DatabaseBackupManagerService`** (**requires mysqldump** in PATH in MySQL setups). Body includes required description. |
| POST | `/maintenance/db-backups/restore` | **Destructive** restore—UI must confirm; service shells to `mysql` client. |

| Ops toggles | Purpose |
| ----------- | ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| POST | `/maintenance/refresh-latest-costs` | Recomputes **`latest_unit_cost`** + **`latest_landed_unit_cost`** for entire catalog via **`ProductLatestCostCacheService::recomputeAll`** (joins newest PO lines by SKU with shipping/surcharge proration logic). |
| POST | `/maintenance/model-kit-collection-filter-manifest` | Regenerates model-kit storefront filter manifest files via **`ModelKitCollectionFilterManifestGeneratorService`** (requires **`OVS_SHOPIFY_THEME_PATH`**). |
| POST | `/maintenance/clear-stale-latest-arrival` | Clears **`latest_arrival`** on products linked to POs older than 4 weeks; Shopify **`tagsRemove`** for **`latest arrival`** tag only (`ShopifyLatestArrivalTagRemoverService`). |
| POST | `/purchase-orders/{id}/workflow-actions/clear-stale-latest-arrival` | Same clear-stale action (PO workflow shortcut). |
| GET | `/purchase-orders/{id}/workflow-actions/set-prices/preview` | Previews prices from PO-line landed cost × 1.5 using the closest X.99 (ties go up). Existing prices are not suggested for update when the difference is $1.00 or less, or when current is already higher; includes a warning when shipping is not entered; groups new/update/unchanged/missing-cost rows. |
| POST | `/purchase-orders/{id}/workflow-actions/set-prices` | Applies formula rows and optional manual `overrides[]` (`product_uuid`, `price`) for PO products, then re-verifies workflow state; logs **`product_selling_price_history`** rows with **`source: po_workflow`** and **`purchase_order_id`** when prices change. |
| GET | `/purchase-orders/{id}/selling-price-history` | Lists selling price changes recorded from this PO’s Set/review apply, **one row per product** (price before this PO → last price set on this PO). Omits net changes of $1.00 or less; first-time prices still appear. |
| GET | `/purchase-orders/{id}/workflow-actions/export-shopify-content/preview` | PO products without handles eligible for Shopify content create (`PurchaseOrderWorkflowExportShopifyContentService`). |
| POST | `/purchase-orders/{id}/workflow-actions/export-shopify-content/push` | Queues PO content export batch (`PurchaseOrderWorkflowExportShopifyContentQueueService` → **`PushSelectedProductToShopifyJob`** per SKU); **202** + `batch_id`. Finalize job runs workflow verify when batch completes. |
| GET | `/purchase-orders/{id}/workflow-actions/export-shopify-content/status` | Poll export progress by `batch_id` — phases: `pushing`, `finalizing`, `complete`, `failed`. |
| GET | `/purchase-orders/{id}/workflow-actions/push-inventory/preview` | PO products eligible for Shopify inventory push (`PurchaseOrderWorkflowPushInventoryService`). |
| POST | `/purchase-orders/{id}/workflow-actions/mark-published-on-shopify` | ERP `published_on_shopify` for all PO products. |
| POST | `/purchase-orders/{id}/workflow-actions/mark-latest-arrival` | ERP `latest_arrival` for PO products (skips `main_type` tools). |
| POST | `/purchase-orders/{id}/workflow-actions/push-inventory` | Queues PO product push batch (`PurchaseOrderWorkflowPushInventoryQueueService` → **`PushSelectedProductToShopifyJob`** per SKU); **202** + `batch_id`. Finalize job runs collection reorder + workflow verify when batch completes. |
| GET | `/purchase-orders/{id}/workflow-actions/push-inventory/status` | Poll push progress by `batch_id` — phases: `pushing`, `finalizing`, `complete`, `failed`. |

| Operational throttles/settings | Meaning |
| ------------------------------ | ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET/PUT | `/maintenance/external-rate-limit` | JSON settings used to pace outbound scraping/API calls (**`ExternalRateLimitService`**). |
| GET/PUT | `/maintenance/external-access` | Toggle/password configuration used by **`ExternalAccessSettingsService`**. Requires secure handling—never log secrets (`project.mdc` external API logging masking rules apply broadly to ops). |

### Price research APIs (`docs/requirements/price-research.md` + `price-research-crawlers.md`)

| Method | Path                             | Purpose                                                                                                                                                                            |
| ------ | -------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/price-research/filter-options` | Populate UI multi-select facets.                                                                                                                                                   |
| GET    | `/price-research/products`       | Paginated table with freshness + quote expansion (see documented query params).                                                                                                    |
| POST   | `/price-research/run`            | Queues **`RunPriceResearchJob`** when async queue ≠ `sync`; else inline completion vs **202 queued** semantics (tests freeze behavior). TTL default **`PRICE_RESEARCH_TTL_DAYS`**. |

| Run observability | Purpose |
| ----------------- | ------------------------------------------------------------ | -------------------------------------------------------- |
| GET | `/price-research/runs/latest`, `/price-research/runs/{uuid}` | Run metadata (**status counters**, durations, failures). |
| GET | `/price-research/runs/{uuid}/logs` | Drill-down text logs for crawler failures. |

| Cleanup / housekeeping | Meaning |
| ---------------------- | -------------------------------------------------- | ------------------------------------------------------------------------------------- |
| DELETE | `/price-research/products/{uuid}/quotes/{siteKey}` | Manual quote eviction for erroneous rows (`PriceResearchQuoteMaintenanceController`). |
| POST | `/price-research/runs/reset` | Maintenance reset for wedged runners (`PriceResearchRunMaintenanceService`). |

| AliExpress / Playwright | Purpose |
| ----------------------- | ------------------------------------ | --------------------------------------------------------------------------------------------- |
| POST | `/price-research/aliexpress/cookies` | Persist cookie jar blobs used by scripted AliExpress lookups (`AliExpressCookiesController`). |

| Reporting backlog | Meaning |
| ----------------- | -------------------------------------- | ---------------------------------------------------------- |
| GET/POST | `/price-research/reports` | List/create “quote issue” reports surfaced in UI workflow. |
| PATCH | `/price-research/reports/{id}/handled` | Numeric id resolution + handled flag bookkeeping. |
| GET | `/reports/staff-orders` | Monthly POS staff / channel order counts (`month=YYYY-MM`). |
| GET | `/reports/inventory-by-main-type` | On-hand inventory stats grouped by department / product line / workshop shelf / grade / subline; SPA tree follows the storefront mega menu. |
| GET | `/reports/customer-retention` | Identified people + New/Repeat/Loyal + RFM + AOV + store-rhythm cadence + own-pace 2×-gap + month-by-month New vs returning (`CustomerRetentionReportService`). |
| GET | `/reports/customer-retention/{personId}` | One merged person plus eligible order refs. |

### Staff orders report (`app/Services/Shopify/Admin/Orders`)

| Method | Path                                                  | Purpose                                                                                                                                                  |
| ------ | ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/shopify/orders`                                     | `ShopifyOrderQueryService` — paginated **`shopify_orders`** list for the Orders page (date/channel/status/`preorder=all\|only`/search). Includes **`has_store_preorder`**, **`customer_email`**, **`customer_phone`**, processed-by / sales-channel labels, and **`store_event_*`**. Store-preorder lines tag the Shopify order **`preorder`** on upsert. |
| POST   | `/shopify/orders/store-event`                         | Batch set or clear `store_event_id` on mirrored orders. |
| GET    | `/shopify/orders/{id}`                                | Same service — one mirrored order plus line items (`is_store_preorder`, description with `(PO)` suffix when applicable). **404** if missing. |
| GET    | `/reports/staff-orders`                               | `ShopifyStaffOrdersMonthlyReportService` — aggregates **`shopify_orders`** mirror (`source_name`, `channel_name`, `pos_user_id`, tags, `payment_gateway_names`) for one calendar month. Extra buckets include **Special order** (draft invoices / special-order tags), **Cash sale** (Shopify Cash gateway or `cash` tag), and **NT sales** (`nt` / `nt-sale` tags), then Quick Sale / Online Store / Shop / POS (other). |
| GET    | `/reports/inventory-by-main-type`                     | `InventoryByMainTypeReportService` — on-hand `available_qty` stats grouped by department / product line / workshop shelf / grade / subline. SPA tree matches the storefront mega menu. |
| GET    | `/reports/customer-retention`                         | `CustomerRetentionReportService` — merge R1–R3, score RFM/AOV/store rhythm/own-pace, paginate; `summary.months` is Eastern Time (Montreal) New vs returning buyers and spend.            |
| GET    | `/reports/customer-retention/{personId}`              | Same snapshot — one person plus order refs (no email/phone).                                                                                             |
| CLI    | `shopify:customer-retention-crosscheck {--live}`      | ERP invariants + API summary; `--live` pages Shopify Admin and compares identity fields and people counts.                                               |
| CLI    | `shopify:orders-backfill-staff-attribution {YYYY-MM}` | Backfill staff attribution columns on existing mirror rows for one month.                                                                                |
| CLI    | `shopify:store-preorders-collection`                  | Upsert smart collection `/collections/pre-orders` (tag `sp:store-preorder`, manual sort). Theme nav **Preorders** (before Miscellaneous) is Liquid on live Rise. |
| CLI    | `shopify:store-preorders-collection-reorder`          | Reorder `/collections/pre-orders` by close date (open first). Queued on the `shopify` queue after open/close/push/shelf-sync. |
| CLI    | `shopify:store-preorders-shelf-sync`                  | Keep tagged Shopify products that still have an ERP store-preorder offer (open or closed). Retag ERP offers missing `sp:store-preorder`. Untag leftovers when the ERP offer is gone but the product/order remains. Delete the Shopify product when ERP is gone and there is no order. |
| CLI    | `store-preorders:close-expired`                       | Close open offers whose `window_ends_on` is before today (America/Toronto) via `StorePreorderCloseService`. Pushes Shopify first (qty 0 while still tagged). Scheduled daily 00:15 America/Toronto. |
| CLI    | `store-preorders:refresh-missing-photos`              | Attach real Plamod pick-list images for open **and closed** store-preorder products missing real photos (rejects Plamod “No image” placeholders), queue a Shopify images-only push (no tag rewrite), and recrawl the Plamod PDP for leftovers. Scheduled daily 07:00 America/Toronto. |
| CLI    | `store-preorders:sync-plamod-descriptions`            | Scrape Plamod retailer PDP **full description** onto store-preorder products (`product_external_contents.source=plamod`, preferred unless Manual/`other`). `--push` writes listing info to Shopify. Skips `OVS-*` manual offers. |
| CLI    | `store-preorders:keep-plamod-images`                  | Delete HLJ assets from store-preorder products; queue Shopify **images-only** push. Recrawl / Get product info / Plamod ZIP sync skip HLJ, Bandai, Gundam Planet, Newtype, and Gundam Hangar image attach on store-preorder products. |
| CLI    | `plamod:instock-sync`                                 | Queue `SyncPlamodInstockJob` (same as **Refresh from PLAMOD**). Scheduled daily 05:00 America/Toronto; skips if a refresh is already queued or running. |
| CLI    | `storefront:model-kit-index-rebuild`                  | Recreate Shopify theme JSON cache `snippets/ovs-model-kit-index-cache.liquid` from published ERP model kits (`ModelKitStorefrontIndexRebuildService`). Successful Shopify upserts of model-kit products poke unique delayed job `RebuildModelKitStorefrontIndexJob` on the `shopify` queue. ERP-only saves and T&S upserts do not poke. Scheduled daily 03:45 America/Toronto. |

### Store events (`app/Services/StoreEvents`)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/store-events` | Calendar list: event summary + one day per date + that day’s eligible orders (`search` optional). |
| POST | `/store-events` | Create (`name`, `starts_on`, `ends_on`, `notes`, `cancelled`). |
| POST | `/store-events/{uuid}/orders` | Tag/untag orders (`order_ids`, `included`). |
| PATCH | `/store-events/{uuid}` | Update. **404** if missing. |
| DELETE | `/store-events/{uuid}` | Delete. **204**. **404** if missing. Clears `shopify_orders.store_event_id`. |

### Marketing notes (`app/Services/StoreMarketing`)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| GET | `/store-marketing-notes` | List dated marketing notes (`search` optional). |
| POST | `/store-marketing-notes` | Create (`name`, `happened_on`, `notes`). |
| PATCH | `/store-marketing-notes/{uuid}` | Update. **404** if missing. |
| DELETE | `/store-marketing-notes/{uuid}` | Delete. **204**. **404** if missing. |

### TCG events (`app/Services/TcgEvents`)

| Method | Path                  | Purpose                                                                                                                              |
| ------ | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| GET    | `/tcg/events`         | Cached JSON feed for UI consumption.                                                                                                 |
| POST   | `/tcg/events/refresh` | Hits Bandai TCG Plus HTTP client (`HttpBandaiTcgPlusApi`) and refreshes storage (see controller + refresh service tests if present). |

### Special orders (admin only)

| Method           | Path                                                     | Notes                                                                                                  |
| ---------------- | -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| GET              | `/special-orders/filter-options`                     | Media, currency, quote/pricing/lifecycle dropdowns.                                                               |
| GET              | `/special-orders/product-name-suggestions`           | Fast kit-name autocomplete (`q`) — Gundam Hangar API + Hobby Sense/Argama Shopify suggest.                         |
| GET              | `/special-orders`                                    | Paginated list (`search`, `contact_media[]`, `workflow_status[]`, …). Response includes top-level **`workflow_status_counts`** (per-step totals for search/media, ignoring timeline filter). |
| POST             | `/special-orders`                                    | Create customer request.                                                                               |
| GET/PATCH/DELETE | `/special-orders/{uuid}`                             | Show, update, delete.                                                                                  |
| POST             | `/special-orders/{uuid}/competitor-prices/refresh`   | Queue async parallel competitor crawl (`scope`: `fast` \| `full`); **202**; `competitor_prices_refresh_status` on order; job crawls sites concurrently via `Concurrency::run`.                         |
| POST             | `/special-orders/{uuid}/reject`                        | Soft reject (`rejected_at`).                                                                           |
| POST             | `/special-orders/{uuid}/revive`                        | Clear `rejected_at`.                                                                                   |
| POST             | `/special-orders/{uuid}/mark-customer-considering`     | Customer thinking about quote (`customer_considering_at`).                                             |
| POST             | `/special-orders/{uuid}/clear-customer-considering`    | Clear customer thinking flag.                                                                          |
| POST             | `/special-orders/{uuid}/deposit-received`              | Mark deposit paid (`deposit_received_at`).                                                            |
| POST             | `/special-orders/{uuid}/balance-received`              | Mark balance paid (`balance_received_at`).                                                             |
| POST             | `/special-orders/{uuid}/cash-received`                 | Record cash received `{ cash_received_cad }`; syncs deposit/balance milestones.                          |
| POST             | `/special-orders/{uuid}/shopify-deposit-invoice`       | Create + send Shopify deposit draft invoice.                                                           |
| DELETE           | `/special-orders/{uuid}/shopify-deposit-invoice`       | Clear ERP deposit invoice record (Shopify unchanged).                                                  |
| POST             | `/special-orders/{uuid}/shopify-balance-invoice`       | Create + send Shopify balance draft invoice.                                                           |
| DELETE           | `/special-orders/{uuid}/shopify-balance-invoice`       | Clear ERP balance invoice record (Shopify unchanged).                                                  |
| POST             | `/special-orders/{uuid}/customer-visual`             | Multipart customer image.                                                                              |
| POST             | `/special-orders/{uuid}/product-visual`              | Multipart product image.                                                                               |
| GET              | `/special-orders/{uuid}/visuals/{customer\|product}` | Inline image view.                                                                                     |
| DELETE           | `/special-orders/{uuid}/visuals/{customer\|product}` | Remove uploaded image; clears DB columns and deletes file from disk.                                   |

See [screens/special-orders.md](../screens/special-orders.md).

### Plamod preorders (`app/Services/Plamod`)

| Method | Path                                       | Purpose                                                                                                                                                                      |
| ------ | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/preorders`                               | Paginated active preorder rows; `new_only` (SKU not in non-archived catalog), `future_releases_only` (release date today or later), `search`, `store_offer`, `interest` (`interested` default / `not_interested` / `all`), `sort` (`name` / `release` / `category` / `stock` / `sell` / `qty` / `closing` / `eta` / `eta_months`), `sort_dir` (`asc` / `desc`), optional `include_closed`, optional `categories[]`. Default pick list requires a Plamod preorder price, excludes in-stock SKUs, past `po_due_date`, and kits marked not interested. Meta includes `category_facets` (name + count). |
| POST   | `/preorders/sync`                          | Queues `SyncPlamodPreordersJob`, which starts a **serial** `Bus::chain` on `plamod_sync` (hub Plastic Model Kits + Figures CSV/JSON/cards → merge/import/offers; image jobs on `default`). |
| GET    | `/preorders/sync-status`                   | Latest `plamod_preorder_sync_logs` snapshot; while running, merges `plamod/preorder_export_progress.json`.                                                                      |
| GET    | `/preorders/settings`                      | Read excluded category list.                                                                                                                                                 |
| PUT    | `/preorders/settings`                      | Persist excluded categories to `app_runtime_settings`.                                                                                                                       |
| POST   | `/preorders/interest`                      | Mark Plamod pick-list SKUs not interested (`not_interested: true`) or interested again. Body: `skus[]`, `not_interested`. Survives snapshot refresh (`not_interested_at`). Opening a store offer clears the mark. |
| GET    | `/preorders/manufacturer-filters`          | Bandai manufacturer series/category-line catalog grouped by decision (`undecided` / `include` / `exclude`).                                                                  |
| POST   | `/preorders/manufacturer-filters/discover` | Queue discover job (no body) or poll status (`job_id`); job scrapes Plamod sidebar and upserts `plamod_preorder_manufacturer_filters`.                                       |
| PUT    | `/preorders/manufacturer-filters`          | Batch update filter decisions (`updates: [{ id, decision }]`).                                                                                                               |
| POST   | `/preorders/search-lines`                  | Multi-line match; optional `phase` (`snapshot` \| `live` \| `all`). Live fallback returns `plamod_only`.                                                                     |
| GET    | `/preorders/{sku}/image`                   | Serve cached image from `storage/app/private/plamod/preorder-images/`.                                                                                                       |

### Store preorders (`app/Services/StorePreorders`)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/store-preorders` | Paginated store preorder offers; `status` (`open` / `closed` / `all`), `closes_on` (`Y-m-d`), `has_units` (boolean: only kits with eligible Shopify preorder units), `search`, `sort_by` (`closing` / `opened` / `name`). Each row includes live Plamod `eta_date` when known, plus `order_count` / `unit_qty` for eligible Shopify preorder lines. Response also has `preorder_orders` (`order_count`, `unit_qty`) and `closing_dates` (distinct `window_ends_on` for the current status). |
| POST | `/store-preorders` | Open offers from Plamod pick-list SKUs. Creates an ERP product when the SKU is new. Attaches the pick-list photo when that file is already on disk, then queues Shopify publish with images (`shopify_queued`). Kits still missing a photo get a listing-only push plus a Plamod PDP crawl (`photos_queued`). Body: `items[]` (`sku`, `deposit_percent`, optional `cap_qty`, optional `window_ends_on`, optional `selling_price`). Missing closing defaults to Plamod due minus 1 day. |
| POST | `/store-preorders/listing-preview` | Crawl a product URL for **Add offer**. Body: `url`. 200: title, `suggested_sku`, description HTML, ETA, retailer price note, staged `images[]`. 422 `no_crawler` when the host is not registered; 422 `crawl_failed` on fetch/parse errors. |
| POST | `/store-preorders/listing-photos` | Stage manual uploads for Add offer (`files[]`). Returns the same image shape as listing-preview. |
| GET | `/store-preorders/listing-photos/{id}` | Preview a staged Add-offer photo. |
| POST | `/store-preorders/manual` | Open one non-Plamod offer. Body: `sku`, `product_name`, `selling_price`, `deposit_percent`, optional `description_html`, `cap_qty`, `window_ends_on`, `eta_date`, `photo_ids[]`. Unique SKU check on save. Creates the ERP product, attaches staged photos, queues Shopify. |
| POST | `/store-preorders/bulk-update` | Apply the same open-offer fields to many rows. Body: `ids[]`, `changes` (`cap_qty`, `deposit_percent`, `selling_price`, `window_ends_on` — only present keys are applied). Closed rows are skipped. Sell $ is snapped to closest X.99 and written to the ERP product selling price. Already-mirrored SKUs are pushed to Shopify after the save. |
| POST | `/store-preorders/bulk-delete` | Delete many offers. Same product-delete rule as a single delete. Body: `ids[]`. |
| POST | `/store-preorders/push-shopify` | Push open offers to Shopify (deposit price, `sp:store-preorder` tag, Online Store). Body optional `ids[]`; empty = all open. |
| POST | `/store-preorders/{id}/close` | Close an open offer (confirm in UI). Sets remaining cap to 0 and `products.available_qty` to 0. Does not delete the product. |
| PATCH | `/store-preorders/{id}/cap` | Update `cap_qty` on an open offer (`null` = no cap). If the SKU is already on Shopify, inventory is updated (no cap = untracked / continue selling). |
| DELETE | `/store-preorders/{id}` | Delete the offer. Also deletes the ERP product when it has no purchase-order or inventory history. Then untag Shopify (`sp:store-preorder`) if the ERP product remains, or delete the Shopify product if ERP is gone and the SKU has no Shopify order. |

### PLAMOD restock (in-stock proposal)

| Method | Path                                      | Purpose                                                                                                                                                                                                                                          |
| ------ | ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| GET    | `/plamod/restock/proposal`                | Existing ERP ∩ in-stock snapshot + new SKUs; existing rows include canonical ERP `products.type`; query `section=all\|existing\|new` (skip the other section’s heavy work), `hide_dismissed`, `only_included_new`; response includes persistent series/product-name exclusion rules. |
| GET    | `/plamod/restock/settings`                | Read persisted shipping estimate percent and automatic new-product exclusion rules.                                                                                                                                                              |
| PUT    | `/plamod/restock/settings`                | Save shipping estimate percent plus optional `excluded_series` and `excluded_product_terms` arrays; omitted arrays preserve their current values.                                                                                                |
| POST   | `/plamod/restock/sync`                    | Queue `SyncPlamodInstockJob` (manufacturer In-Stock CSV → `plamod_instock_items`). Same path as scheduled `plamod:instock-sync` (daily 05:00 America/Toronto; skips if a refresh is already queued/running).                                                                 |
| POST   | `/plamod/restock/sync-retry`              | Queue a merge-only retry of selected failed brand filters from the last completed refresh (`filters[]` with `name`, `tab`, `category_id`, `expected`). Refuses when a sync is already queued/running.                                              |
| GET    | `/plamod/restock/sync-status`             | Latest `plamod_instock_sync_logs` snapshot; while queued/running, merges `plamod/instock_export_progress.json` from shared storage (no scraper HTTP). Exposes `failed_filters` for the restock retry card.                                         |
| PUT    | `/plamod/restock/decisions/{sku}`         | Dismiss or include new SKU (`order_qty`, `planned_maintain_qty` when included).                                                                                                                                                                  |
| POST   | `/plamod/restock/decisions/bulk`          | Bulk dismiss or include new SKUs (`skus[]`, `status`, optional qtys when included).                                                                                                                                                              |
| PUT    | `/plamod/restock/reorder-overrides/{sku}` | Persist or clear existing-SKU order qty override (`reorder_qty`, nullable to reset).                                                                                                                                                             |
| POST   | `/plamod/restock/draft-purchase-order`    | Create Plamod draft PO from proposal (existing + catalog-less included lines).                                                                                                                                                                   |
| POST   | `/plamod/restock/cart-run`                | Queue `SyncPlamodRestockCartJob` — body `{ skus[] }` required; rejects concurrent cart runs, freezes selected proposal quantities at dispatch, sets each PDP to its requested cart total, then performs one final verification snapshot.         |
| POST   | `/plamod/restock/cart-run-recheck`        | Sync re-scrape PLAMOD cart for latest run report (`cart_before` baseline); updates stored verification report.                                                                                                                                   |
| GET    | `/plamod/restock/cart-run-status`         | Latest `plamod_restock_cart_runs` snapshot; merges scraper progress while running; exposes `report`, `summary`, `all_verified`.                                                                                                                  |
| POST   | `/plamod/restock/order-verify`            | Sync scrape PLAMOD cart against **all** proposal order lines (`proposed_qty` + included new `order_qty`); persists report to `app_runtime_settings` key `plamod_restock.order_verify_report`; exposes `order_matches_cart` + `extra_cart_lines`. |
| GET    | `/plamod/restock/order-verify`            | Last stored full-order verification snapshot.                                                                                                                                                                                                    |

Cart dispatch health requires scraper routes `POST /restock-add-to-cart`, `POST /restock-verify-cart`, and `GET /restock-cart-progress`. The queue job marks its run failed through `failed()` if the worker times out or terminates with an exception, preventing permanently active run state.

Scheduled: `plamod:preorders-sync` daily 06:00 America/Toronto (same as **Refresh from Plamod**; skips if a refresh is already queued/running). Finalize also runs `PlamodPreorderOfferEnrichService` (PDP offer fill + persist Plamod full description when the product exists, cap 400 SKUs) after the manufacturer sidecar import.

---

## Shopify content CSV — body HTML precedence (IMPLEMENTED)

Requirements doc `handle-and-shopify-content-export.md` states a simple cascade **HLJ → competitor (most recent) → Plamod → empty**.

**Actual `ShopifyContentExportService::resolveBodyHtml`**:

1. If **`preferred_description_source`** non-empty **and** resolves to usable HTML (`hlj` row, `plamod` row, or **`product_external_contents.source` match** ignoring empties)—return that HTML. Store-preorder Plamod opens set preferred to **`plamod`** when the retailer PDP has a full description (Manual/`other` stays).
2. Else HLJ `description_html`.
3. Else among **`product_external_contents` excluding explicit `hlj` + `plamod` competitor rows**, choose **maximum `updated_at` timestamp**.
4. Else Plamod `description_html`.
5. Else empty string.

Normalization before CSV write strips `<br>` (Shopify spacing policy), collapses stray Unicode artifacts, trims inter-tag whitespace.

### Image Src signing

Signed URLs expire in **72 hours** server-side timestamp. Implemented as **path-based** segments (no reliance on Shopify preserving querystrings). Filename segment uses URL-encoded **`asset.filename`** basename fallback `image.png`.

### Which images export

Uses relationship **`shopifyImageAssets`**:

- **`shopify_enabled = true`**
- `kind === image` OR `mime_type LIKE image/%`
- Ordered by `sort_order`, then `id`
- Files verified **readable** on disk; missing/unreadable silently skipped (**auto-repairs permissions** attempt via **`ShopifyImageServeService::repairStoragePath`**)

---

## Key domain aggregates (mental model)

| Concept                 | Stored / derived                       | Notes                                                                                                                                                            |
| ----------------------- | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Selling price**       | `product_selling_prices.selling_price` | Drives Shopify primary export exclusions + price research UI cross-checks where wired.                                                                           |
| **Available inventory** | `products.available_qty`               | Updated via Shopify import, inventory-check apply flows, PO apply-received, overrides. FIFO/lot deductions live under `Inventory` services when stock allocated. |
| **Latest landed cost**  | `products.latest_*` caches             | Derived from newest PO receipt context + shipping allocations (`ProductLatestCostCacheService`).                                                                 |
| **Archived products**   | `products.archived_at`                 | Removed from PDP bulk targets that call `scopeNotArchived`, Shopify content queries, barcode scans unless explicitly tested otherwise.                           |

---

## Background queue jobs (`app/Jobs`)

| Job                               | Typical trigger                                                                                                                                                                                                                                                                                                                                        |
| --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `SyncProductInfoJob`              | **`POST /products/{id}/product-info/sync`** only (direct `dispatch`, **not** enqueued inside `sync-missing-info` batches). Implements `Batchable` + `SkipIfBatchCancelled` but **usual path has no Laravel batch parent** (`$this->batch()` null) unless tests/mocks attach one—`JobBatchItemService` mutations then no-op guarded by batch id checks. |
| `RunPriceResearchJob`             | `/price-research/run` asynchronous mode                                                                                                                                                                                                                                                                                                                |
| `RecrawlSelectedProductJob`       | `/products/recrawl/selected`, store-preorder leftovers, and daily `store-preorders:refresh-missing-photos` leftovers (no real pick-list photo). After a Plamod source run, store-preorder products are queued for a Shopify image update (or images-only clear when the PDP is still a “No image” graphic). |
| `PushSelectedProductToShopifyJob` | `/products/shopify-push/selected`, store-preorder open (`store_preorder_shopify_push`), and store-preorder image follow-up after pick-list attach or Plamod crawl |
| `SyncPlamodAssetsJob`             | **`POST /products/{id}/plamod/sync`** (immediate dispatch) **and** bulk **`POST /products/sync-missing-info`** batches. Wraps **`PlamodAssetSyncService`**, logs **`plamod.sync.completed`** with **`backup_created`**, **`assets_count`**.                                                                                                            |
| `RenameSelectedProductAssetsJob`  | `/products/bulk/plamod-assets/rename`                                                                                                                                                                                                                                                                                                                  |

---

## Operational Artisan commands (`app/Console/Commands`)

Aside from scaffolded `routes/console.php` `inspire` placeholder, substantive commands shipped include **data migrations**, barcode backfills, PO repair utilities, **`DatabaseBackupCommand`**, **`ProductsRenameDspiaePaintsCommand`** (DSPiae SKU rename pipeline), **`HljInspectImagesCommand`** (HLJ diagnostics), **`TransferSqliteToMysqlDataCommand`**, **`InventoryOpeningBalanceBackfillCommand`**, **`PurchaseOrderFixOpeningBalanceQtyCommand`**, **`PurchaseOrderReassignOpeningBalanceItemCommand`**.

Use **`php artisan list`** after dependency updates to confirm registrations.

---

## HTTP client defaults (SPA)

Axios singleton in **`resources/js/lib/api.ts`** is configured with a **request timeout (`60000` ms)** to accommodate large payloads (manual uploads)—adjust only with coordinated QA on slow networks.

---

## Spec ↔ code divergence log (explicit)

Track intentional differences here when updating requirements:

| Topic                 | Spec                                                             | Code behavior                                                                                                                                                                                                                                                                                                   |
| --------------------- | ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Shopify body HTML     | Fixed cascade (HLJ → competitor snapshot → Plamod)               | **Preferred description source overrides** cascade; competitor selection uses **`updated_at`**, not strictly “during price research run.”                                                                                                                                                                       |
| Bulk PDP completeness | Describes job batch UX generically (`pdp-content-ingestion.md`). | **`sync-missing-info` batches **`SyncPlamodAssetsJob` → **`PlamodAssetSyncService` only.** Manual **`product-info/sync`** (**`SyncProductInfoJob`**) adds **Bandai + GundamPlanet + Newtype + GundamHangar** attempts—not part of bulk missing-info pipeline. Plan migrations accordingly if you expect parity. |

---

## Suggested verification before large changes

Run targeted suites when touching domains:

- Products / exports: `tests/Feature/Api/V1/Product*`, `ShopifyContentExportApiTest`.
- PDP ingestion: PDP + job batch specs.
- Purchase orders: `tests/Feature/Api/V1/PurchaseOrder*`.
- Inventory: `InventoryCheck*`, `EmployeeInventory*` if present.
- External gate: `ExternalAccessMiddlewareTest`.

---

_Last generated as a consolidation pass over `routes/*.php`, `resources/js/router.ts`, and representative services; extend with screenshots / UX notes when refactoring UI-heavy flows._
