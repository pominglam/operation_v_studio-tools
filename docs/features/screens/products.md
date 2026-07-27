# Products (`/products`)

**Page:** `resources/js/pages/ProductsPage.vue`  
**Table:** `resources/js/components/products/ProductsTable.vue`  
**Drawers:** `PlamodDrawer.vue`, `ProductPoLinesDrawer.vue`

## Tabs (hash synced)

Tabs update the Vue Router **`hash`** so URLs are shareable and survive refresh:

| Tab button | Internal key | Typical hash |
| ---------- | ------------ | ------------ |
| Products   | `list`       | `#products`  |
| Add        | `add`        | `#add`       |
| Import     | `import`     | `#import`    |
| Export     | `export`     | `#export`    |

`/import` route redirects here with **`#import`**.

---

## Tab: Products (main grid)

### Global actions

- **Reset filters** — clears search mode, typed filters, multi-selects; reloads (`data-testid=products-reset-filters-button`).
- **Refresh** — re-fetches current page/filters without clearing them.
- **Export filtered results** — downloads a UTF‑8 BOM catalog CSV of **all** products matching the active list filters across every page (`data-testid=products-export-filtered-button` → **`GET /api/v1/products/export/filtered`** with the same query params as the list).

### Search

- **Single** — single text field (`SKU`, `barcode`, `description` server-side semantics per API).
- **Bulk** — monospace textarea (**one token per line**); server receives `search_terms` array; union of matching products (see `splitBulkSearchTerms`, max parsed lines guarded). Displays count of parsed lines · “union of matches”. Buttons: **Clear**, **Search list** (`products-bulk-search`).

### Multi-select filters (from `GET /api/v1/products/filter-options`)

- **Main type** — `products-filter-main-type`.
- **Type** — `products-filter-type`.
- **Vendor**
- **PO** — filters catalog to SKUs appearing on chosen purchase orders (`purchaseOrderUuids` query param family).
- **PO novelty** (when PO filter used): **New + existing**, **New in selected PO**, **Existing in selected PO** (`products-filter-po-novelty`).

### Add product

`AddProductForm` posts **`POST /api/v1/products`** and is reused by the inventory-check resolve dialog. **Vendor** uses existing vendor options; **Main type** and **Type** are typed combo fields backed by `GET /api/v1/products/filter-options`, so operators can pick an existing taxonomy value or type a new one inline.

### Other filters

- **Missing info** — drives “PDP completeness” style gaps (`barcode`, `selling_price`, `pdp_description`, `pdp_images`, `ok`, etc.—exact vocabulary from API/filter options wiring).
- **Ready** dropdown: **All**, **Ready only**, **Not ready only** (`products-filter-ready`).
- **Published** dropdown: **All**, **Published only**, **Not published only** (`products-filter-published`; query param `published=all|published|not_published` on **`GET /api/v1/products`**; filters `products.published_on_shopify`).
- **Product flags** — multi-select (`products-filter-product-flags`): **Critical**, **Discontinued**, **Hazardous shipment**; sent as `product_flags[]` on **`GET /api/v1/products`**. Multiple selections use **OR** (match any selected flag).
- **Shipment** — multi-select (`products-filter-shipment-methods`): **Air**, **Sea**; sent as `shipment_methods[]` on **`GET /api/v1/products`**. Multiple selections use **OR** (match air **or** sea).
- **Archived** dropdown — **Active only** (default), **All (active + archived)**, **Archived only** (`products-filter-archived`; query param `archived=active|all|archived`; legacy `include_archived=1` maps to **all**).
- **Available** — min/max integer inputs (`products-filter-available-min`, `products-filter-available-max`); sent as `available_min` / `available_max` on **`GET /api/v1/products`** (inclusive range on `available_qty`).
- **Not arrived**, **Reorder** — free-text numeric filters (parses non-negative ints client-side helper).
- **Selling price** — min/max decimal inputs (`products-filter-selling-price-min`, `products-filter-selling-price-max`); sent as `selling_price_min` / `selling_price_max` on **`GET /api/v1/products`** (inclusive range on `product_selling_prices.selling_price`; products without a price are excluded when either bound is set).
- **Reorder > 1** checkbox — tightening filter on reorder column logic.
- **Per page** — `25 … 1000`.

### Sorting

`ProductsTable` exposes sortable columns matching `ProductSortKey` (**SKU, barcode, description, taxonomies, landed cost, received date, selling price, totals, available, hold, demand, maintain, not_arrived, reorder**, …); toggling re-hits **`GET /api/v1/products`** with sort params.

The taxonomy classification columns (**main type**, **type**, **grade**, **scale**, **series**) are hidden by default to keep the grid compact; **Show type/grade/scale** reveals them for sorting and inline edits.

### Row model (fields users see / edit)

Key row fields (`ProductRow` type): SKU, barcode, description, handle, **main_type** / **type** / **grade** / **series** / **scale**, vendor, **archived**, **published_on_shopify**, **is_ready**, **latest_arrival**, **is_critical**, **is_discontinued**, **is_hazardous_shipment**, **shipment_method** (`air` | `sea` | null), **`latest_*_cost`**, **received_date**, selling price snapshot, PDP flags (**has_description**, **plamod_image_count**), **total_ordered**, **shopify_orders_count**, **available**, **hold**, **`sold_4w`**, **maintain**, **not_arrived**, **reorder**. **Total sold** renders ERP-derived sold units plus `({shopify_orders_count})`; clicking the count opens **`ProductDemandDetailDialog`** to the existing recent Shopify lines. Cancelled / voided Shopify orders are excluded. **Not arrived** sums open PO line qty (`received_date` null); filter checkbox **Include draft POs** toggles `not_arrived_include_draft_orders` (draft = no ordered/shipped/received dates). **Reorder** uses the same not-arrived basis as the list request.

- **Sold 4 wk** — read-only rollup (units sold in rolling **28 days**: shopify + assumed); column header label **4 wk sold**; sort key **`demand`**; click opens **`ProductDemandDetailDialog`** → **`GET /api/v1/products/{id}/demand`** (`lines_page`, `lines_per_page` for recent lines; weekly rollups show **all weeks** in the 365-day window including zeros). Shopify order lines from **cancelled** orders (**`cancelled_at`** or **`VOIDED`** financial status) are excluded from rollups, the recent-lines list, and the total-sold order count.

### Inline edits (single row)

The table wires many cells to PATCH endpoints (IDs are product UUID):

- Metadata / SKU / description / taxonomy → **`PATCH /api/v1/products/{id}`** payload families.
- **Available** qty → **`PATCH .../available`** (total on-hand, including held units).
- **Hold** qty → **`PATCH .../hold`** (`hold_qty`; must be `0…available`). Units withheld from Shopify push; subtracted only at inventory push/export to Shopify (`shopify_push_qty = available - hold`).
- **Maintain** qty → **`PATCH .../maintain`** (paired domain column).
- **Ready** checkbox → **`PATCH .../ready`**.
- **Latest arrival** checkbox → **`PATCH .../latest-arrival`**.
- **Critical / Discontinue / Hazardous shipment** — checkboxes under the product name (SKU/barcode/handle block); not separate columns. **`PATCH .../critical`** (`is_critical`), **`PATCH .../discontinue`** (`is_discontinued`), **`PATCH .../hazardous-shipment`** (`is_hazardous_shipment`, default false). Checking **Discontinue** or **Hazardous shipment** opens a confirm dialog before saving; unchecking applies immediately. Updates are **optimistic** (no full list reload; the grid stays mounted).
- **Shipment** — dropdown under the product name (**—**, **Air**, **Sea**); **`PATCH .../shipment-method`** (`shipment_method` nullable). Same optimistic in-row update (no table teardown).
- **List refresh** — filter/sort/page changes show a light **Refreshing…** overlay on the grid instead of replacing the table with a blank loading state.

### Drawer: “Info” (PDP bundle)

Opens **`PlamodDrawer`** (**Info** UX): product identity + **Get product info** (dispatches **`SyncProductInfoJob`** via **`POST /api/v1/products/{id}/product-info/sync`**, HTTP **202**) + carousel / description / attrs / selling price + **preferred description source** PATCH + asset ordering / manual upload / Shopify-enabled toggles / **manual image delete with confirm** (`DELETE /api/v1/product-assets/{numericId}` — only **`manual_upload`** source).

Detailed backend semantics: `docs/requirements/pdp-content-ingestion.md`, `docs/requirements/handle-and-shopify-content-export.md`, and **`docs/features/backend/system-catalog-services-and-http.md`**.

### Drawer: PO lines

**`ProductPoLinesDrawer`**: **`GET /api/v1/products/{id}/po-lines`** for operational context (“which PO touched this SKU” presentation).

### Selection & bulk toolbar

Supports **page selection** vs **Select all matching** (cache key **`selectionScopeKey`**—invalidates selection when filters/sorts change materially).

Bulk actions (confirmation dialogs vary by action — see **`ConfirmDialog`**, **`BulkUpdateDialog`**):

| Action                                        | API / behavior summary                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Bulk delete**                               | `POST /api/v1/products/bulk-delete` — confirm required in UI                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Bulk archive**                              | `POST /api/v1/products/bulk-archive`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| **Bulk update** (`BulkUpdateDialog`)          | `POST /api/v1/products/bulk-update` — published Shopify, archive, SKU fields subset, qty fields, **critical / discontinued / hazardous shipment**, **shipment_method**, …                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| **Bulk export selected** (`BulkExportDialog`) | See **Export formats** row below                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| **Bulk recrawl** (`BulkRecrawlDialog`)        | `POST /api/v1/products/recrawl/selected` with sources **`bandai`**, **`hlj`**, **`gundamplanet`**, **`newtype`**, **`gundamhangar`**, **`argama`**, **`plamod`**, **`competitor_price_research`** checkbox matrix                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| **Push to Shopify** (`BulkPushShopifyDialog`) | **`POST /api/v1/products/shopify-push/preview`** then **`POST /api/v1/products/shopify-push/selected`** — checkbox matrix: **`info`**, **`images`**, **`quantities`**, **`price`**, **`publish_status`**, **`sales_channels`**. Preview returns create/update/skip counts, scope/tunnel/location warnings. Queue returns **202** + **`batch_id`** → **`/sync-progress`** (`push_selected_products_shopify` batch). Uses same `productSet` upsert as PO push; sellable qty = available − hold. **Image updates on existing products:** when **images** is checked, Shopify media is cleared then ERP `shopify_enabled` assets are uploaded; when **images** is unchecked but ERP has **zero** `shopify_enabled` images, stale Shopify media is still removed (typical bulk matrix: info/qty/price/publish without images). When **images** is checked, the job **auto-starts** the Cloudflare tunnel if needed (`ShopifyImageTunnelLeaseService`), **verifies each signed image URL is HTTP-reachable**, waits until Shopify media status is **READY** (poll up to 120s), then **restores** the prior running/stopped state. Failed media (`FAILED`) fails the batch item. Image assets are renamed to SEO-friendly filenames immediately before upload. New Shopify products require **info + price** toggles. |
| **Create draft PO** (when handler present)    | `POST /api/v1/purchase-orders/drafts/create-from-products` — lines use **`latest_unit_cost`** (fallback **`latest_landed_unit_cost`**); PO header **`product_total`** / **`shipping_total`** (CAD) are estimated from lines (shipping ≈ last landed − unit × qty)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |

**Bulk export types** (`ProductsBulkExportType`):

| `exportType`                                       | Behavior                                                                                                                                                              |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `shopify` / `shopify_no_inventory`                 | **`POST /api/v1/products/export/selected`** with blob download; Shopify CSV family                                                                                    |
| `shopify_content` / `shopify_content_no_inventory` | **`POST`** `.../shopify-content/prepare` variants with `{ ids }` → follow `download_url`                                                                              |
| `shopify_content_rename_export`                    | Queue **`POST /api/v1/products/bulk/plamod-assets/rename`** → redirects to **`/sync-progress`** with **`auto_export=shopify_content`** + `sessionStorage` hint bundle |

Standard **catalog export** buttons (filtered list, not bulk-id based) assign **`window.location`** to **`GET /api/v1/products/export`** attaching current **sort_by / sort_dir** + **format** (`shopify` vs `shopify_no_inventory`) via export tab controls.

Additional quick downloads reachable from tooling:

- **Missing barcode** CSV — `GET .../export/missing-barcode` with same sorting params.
- **Barcoded UTF‑8 BOM** inventory CSV — **`GET .../export/barcoded`** (foundation for Inventory Check workflows).

---

## “Sync missing PDP info” banner

Near pagination:

- **`Sync progress`** anchor → `/sync-progress`.
- Shows **mini progress bar** when a tracked **`batch_id`** is active (**polled via job-batches endpoints** client-side refs `syncBatchId` / `syncBatchStatus`).
- **Sync missing PDP info** opens confirm dialog (**Queue sync**) → **`POST /api/v1/products/sync-missing-info`** carries current **filters** (`search`, `types`, `vendors`, **`missing`** facets for `pdp_description`/`pdp_images`, optional **`dry_run`**) — returns **`batch_id`**, navigates **`/sync-progress?batch_id=...`**.

Bulk pipeline uses **`SyncPlamodAssetsJob`** batches (not the single-product **`SyncProductInfoJob`**). See divergence notes in **`docs/features/backend/system-catalog-services-and-http.md`**.

---

## Tab: Add

Uses **`AddProductForm.vue`** posting **`POST /api/v1/products`** with SKU-first payload; surfaces success/errors inline (`creating`, messages).

---

## Tab: Import

Composable cards stacked vertically:

| Card component                            | Endpoint                                                  | Purpose                                                                          |
| ----------------------------------------- | --------------------------------------------------------- | -------------------------------------------------------------------------------- |
| **`ImportProductsCard`**                  | **`POST /api/v1/products/import`** multipart              | Canonical product spreadsheet import (+ conflict/error reporting surfaced in UI) |
| **`ImportInventoryCard`**                 | **`POST /api/v1/products/import-inventory`**              | Shopify qty CSV (**Variant SKU**, **Variant Inventory Qty**)                     |
| **`ImportInventoryQuantityOverrideCard`** | **`POST /api/v1/products/import-inventory-qty-override`** | Hard override qty import                                                         |
| **`ImportHandlesCard`**                   | **`POST /api/v1/products/import-handles`**                | Shopify **`Handle`** overlays                                                    |

Exact CSV contracts: **`docs/requirements/shopify-export-and-inventory.md`**, **`docs/requirements/handle-and-shopify-content-export.md`**.

---

## Tab: Export

Cards / controls:

| UI                                 | Backend                                                                                                                                       |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| **Shopify CSV** download           | **`GET /api/v1/products/export`** (uses `format` + sort params wired like list tab defaults)                                                  |
| **Missing barcode** CSV            | **`GET .../export/missing-barcode`**                                                                                                          |
| **Missing selling price** workflow | Loads preview list via **`GET .../export/missing-selling-price`** (client shows table + guidance)                                             |
| **Barcoded products** CSV          | **`GET .../export/barcoded`**                                                                                                                 |
| **`ReplenishmentExportCard`**      | **`GET .../replenishment/preview`** + **`GET .../replenishment/export`**                                                                      |
| **`ShopifyContentExportCard`**     | **Tunnel**: `GET/POST /api/v1/shopify/image-tunnel*`; **Prepare** uses same prepare endpoints + download link pattern as bulk Shopify content |

---

## State persistence (`pageState` helper)

Several filter/search/toggle blobs saved per admin workflow (inspect `STATE_KEY`-style refs in **`ProductsPage.vue`**) — restoring on mount for continuity across reloads within the same browser profile.
