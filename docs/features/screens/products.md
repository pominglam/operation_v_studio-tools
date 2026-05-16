# Products (`/products`)

**Page:** `resources/js/pages/ProductsPage.vue`  
**Table:** `resources/js/components/products/ProductsTable.vue`  
**Drawers:** `PlamodDrawer.vue`, `ProductPoLinesDrawer.vue`

## Tabs (hash synced)

Tabs update the Vue Router **`hash`** so URLs are shareable and survive refresh:

| Tab button | Internal key | Typical hash |
| --- | --- | --- |
| Products | `list` | `#products` |
| Add | `add` | `#add` |
| Import | `import` | `#import` |
| Export | `export` | `#export` |

`/import` route redirects here with **`#import`**.

---

## Tab: Products (main grid)

### Global actions

- **Reset filters** — clears search mode, typed filters, multi-selects; reloads (`data-testid=products-reset-filters-button`).
- **Refresh** — re-fetches current page/filters without clearing them.

### Search

- **Single** — single text field (`SKU`, `barcode`, `description` server-side semantics per API).
- **Bulk** — monospace textarea (**one token per line**); server receives `search_terms` array; union of matching products (see `splitBulkSearchTerms`, max parsed lines guarded). Displays count of parsed lines · “union of matches”. Buttons: **Clear**, **Search list** (`products-bulk-search`).

### Multi-select filters (from `GET /api/v1/products/filter-options`)

- **Main type** — `products-filter-main-type`.
- **Type** — `products-filter-type`.
- **Vendor**
- **PO** — filters catalog to SKUs appearing on chosen purchase orders (`purchaseOrderUuids` query param family).
- **PO novelty** (when PO filter used): **New + existing**, **New in selected PO**, **Existing in selected PO** (`products-filter-po-novelty`).

### Other filters

- **Missing info** — drives “PDP completeness” style gaps (`barcode`, `selling_price`, `pdp_description`, `pdp_images`, `ok`, etc.—exact vocabulary from API/filter options wiring).
- **Ready** dropdown: **All**, **Ready only**, **Not ready only** (`products-filter-ready`).
- **Include archived** checkbox — sends include-archived semantics to backend (`notArchived()` default excludes archived SKUs unless opted in).
- **Available**, **Not arrived**, **Reorder** — free-text numeric filters (parses non-negative ints client-side helper).
- **Reorder > 1** checkbox — tightening filter on reorder column logic.
- **Per page** — `25 … 1000`.

### Sorting

`ProductsTable` exposes sortable columns matching `ProductSortKey` (**SKU, barcode, description, taxonomies, landed cost, received date, selling price, totals, available, maintain, not_arrived, reorder**, …); toggling re-hits **`GET /api/v1/products`** with sort params.

### Row model (fields users see / edit)

Key row fields (`ProductRow` type): SKU, barcode, description, handle, **main_type** / **type** / **grade** / **series** / **scale**, vendor, **archived**, **published_on_shopify**, **is_ready**, **latest_arrival**, **`latest_*_cost`**, **received_date**, selling price snapshot, PDP flags (**has_description**, **plamod_image_count**), **total_ordered**, **available**, **maintain**, **not_arrived**, **reorder**.

### Inline edits (single row)

The table wires many cells to PATCH endpoints (IDs are product UUID):

- Metadata / SKU / description / taxonomy → **`PATCH /api/v1/products/{id}`** payload families.
- **Available** qty → **`PATCH .../available`**.
- **Maintain** qty → **`PATCH .../maintain`** (paired domain column).
- **Ready** checkbox → **`PATCH .../ready`**.
- **Latest arrival** checkbox → **`PATCH .../latest-arrival`**.

### Drawer: “Info” (PDP bundle)

Opens **`PlamodDrawer`** (**Info** UX): product identity + **Get product info** (dispatches **`SyncProductInfoJob`** via **`POST /api/v1/products/{id}/product-info/sync`**, HTTP **202**) + carousel / description / attrs / selling price + **preferred description source** PATCH + asset ordering / manual upload / Shopify-enabled toggles / **manual image delete with confirm** (`DELETE /api/v1/product-assets/{numericId}` — only **`manual_upload`** source).

Detailed backend semantics: `docs/requirements/pdp-content-ingestion.md`, `docs/requirements/handle-and-shopify-content-export.md`, and **`docs/features/backend/system-catalog-services-and-http.md`**.

### Drawer: PO lines

**`ProductPoLinesDrawer`**: **`GET /api/v1/products/{id}/po-lines`** for operational context (“which PO touched this SKU” presentation).

### Selection & bulk toolbar

Supports **page selection** vs **Select all matching** (cache key **`selectionScopeKey`**—invalidates selection when filters/sorts change materially).

Bulk actions (confirmation dialogs vary by action — see **`ConfirmDialog`**, **`BulkUpdateDialog`**):

| Action | API / behavior summary |
| --- | --- |
| **Bulk delete** | `POST /api/v1/products/bulk-delete` — confirm required in UI |
| **Bulk archive** | `POST /api/v1/products/bulk-archive` |
| **Bulk update** (`BulkUpdateDialog`) | `POST /api/v1/products/bulk-update` — published Shopify, archive, SKU fields subset, qty fields, … |
| **Bulk export selected** (`BulkExportDialog`) | See **Export formats** row below |
| **Bulk recrawl** (`BulkRecrawlDialog`) | `POST /api/v1/products/recrawl/selected` with sources **`bandai`**, **`hlj`**, **`gundamplanet`**, **`newtype`**, **`gundamhangar`**, **`argama`**, **`plamod`**, **`competitor_price_research`** checkbox matrix |
| **Create draft PO** (when handler present) | `POST /api/v1/purchase-orders/drafts/create-from-products` |

**Bulk export types** (`ProductsBulkExportType`):

| `exportType` | Behavior |
| --- | --- |
| `shopify` / `shopify_no_inventory` | **`POST /api/v1/products/export/selected`** with blob download; Shopify CSV family |
| `shopify_content` / `shopify_content_no_inventory` | **`POST`** `.../shopify-content/prepare` variants with `{ ids }` → follow `download_url` |
| `shopify_content_rename_export` | Queue **`POST /api/v1/products/bulk/plamod-assets/rename`** → redirects to **`/sync-progress`** with **`auto_export=shopify_content`** + `sessionStorage` hint bundle |

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

| Card component | Endpoint | Purpose |
| --- | --- | --- |
| **`ImportProductsCard`** | **`POST /api/v1/products/import`** multipart | Canonical product spreadsheet import (+ conflict/error reporting surfaced in UI) |
| **`ImportInventoryCard`** | **`POST /api/v1/products/import-inventory`** | Shopify qty CSV (**Variant SKU**, **Variant Inventory Qty**) |
| **`ImportInventoryQuantityOverrideCard`** | **`POST /api/v1/products/import-inventory-qty-override`** | Hard override qty import |
| **`ImportHandlesCard`** | **`POST /api/v1/products/import-handles`** | Shopify **`Handle`** overlays |

Exact CSV contracts: **`docs/requirements/shopify-export-and-inventory.md`**, **`docs/requirements/handle-and-shopify-content-export.md`**.

---

## Tab: Export

Cards / controls:

| UI | Backend |
| --- | --- |
| **Shopify CSV** download | **`GET /api/v1/products/export`** (uses `format` + sort params wired like list tab defaults) |
| **Missing barcode** CSV | **`GET .../export/missing-barcode`** |
| **Missing selling price** workflow | Loads preview list via **`GET .../export/missing-selling-price`** (client shows table + guidance) |
| **Barcoded products** CSV | **`GET .../export/barcoded`** |
| **`ReplenishmentExportCard`** | **`GET .../replenishment/preview`** + **`GET .../replenishment/export`** |
| **`ShopifyContentExportCard`** | **Tunnel**: `GET/POST /api/v1/shopify/image-tunnel*`; **Prepare** uses same prepare endpoints + download link pattern as bulk Shopify content |

---

## State persistence (`pageState` helper)

Several filter/search/toggle blobs saved per admin workflow (inspect `STATE_KEY`-style refs in **`ProductsPage.vue`**) — restoring on mount for continuity across reloads within the same browser profile.
