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

## Canonical taxonomy review (`/products/taxonomy`)

`ProductTaxonomyReviewPage.vue` is the operator queue for additive canonical taxonomy:
**department, manufacturer, franchise/IP, product line, sub-line, grade, series/title, scale**, plus **Tools & Supplies shelf** (`workshop_shelf`), **filter facets** (`workshop_facets` JSON), and **model kit accessory kind** (`accessory_kind` when department is `accessories`).

- Summary cards show the latest completed research run.
- Filters combine status, SKU/name, every canonical field, a selected missing field, confidence threshold, archive state, and **differences only**.
- The queue is a **full-width review grid**: one row per product, with SKU, title, status, confidence, and every canonical field as columns. Changed proposals are highlighted and show the previous value as **was**.
- **Series decision** — click the **Series** cell to open a popup comparing **ERP**, **Plamod**, **title rules**, **cached gunpla.fandom**, and **Bandai sync** (`bandai_series` on external content), with suggested confidence (`high` / `medium` / `review`). Wiki lookups are cache-only on this screen (no live HTTP). Choose or type a series value and **Save series** to write ERP.
- **Show evidence** expands the selected row with source URLs and confidence. **Approve** applies an unchanged proposal (proposed rows only); **Edit** saves field changes to ERP on any status.
- **Bulk update** applies operator overrides to checked rows (**proposed**, **verified**, or **overridden**). Select rows, choose fields, then **Apply override**. Test SKUs are skipped.
- **Approve high-confidence** confirms, then applies matching proposed rows with confidence ≥ 90. Test SKUs and model kits without a manufacturer are skipped.
- **Export confirmation list** downloads the current filter set as CSV.
- **Research selected (N)** opens a dialog to pick which canonical fields to re-derive (all checked by default; **Series only** preset). Checked rows only; each becomes **proposed** with fresh evidence for those fields — unchecked fields keep current proposed values; **ERP is not updated** until approve. API accepts optional `fields[]`. Skips test SKUs and stale-run rows. Full-catalog research via **`POST /api/v1/products/taxonomy/research`** (CLI/jobs only — not in UI). T&S shelf/facets derive from storefront classifier rules, persist on the ERP product, and push as **`ovs_taxonomy` metafields** plus live `ts:*` tags when a department is pushed (`products:storefront-push-department`). Adhesives facets: `adhesive_flow` (low/high) and `adhesive_thickness` (extra-thin/regular) — storefront `/collections/adhesives`.
- **`php artisan products:taxonomy-reclassify`** re-opens **verified** rows when updated rules disagree with applied product values (default patterns: keychain, figures, dspiae-mp, panel-liner). CHARZAKU-KUN / GUNPLA-KUN / ZAKUPLA-KUN rubber mascot **keychains** are Miscellaneous (`misc` / Keychains), not the Gunpla-kun model-kit shelf. Cutting mats and other `department` tools/paints/supplies never get `mk:*` tags (they stay on Tools & Supplies shelves). Liquid Stedi panel liners (`MP-10+`, type **Panel liner**) use product line **Stedi Panel Liners** — they are never **Stedi Markers**.
- **`php artisan products:model-kit-taxonomy-audit`** — batch audit of model-kit storefront scope: ERP vs derivation vs `mk:*` tag resolver; writes `storage/app/model-kit-taxonomy-audit.md` for operator approval. Workflow and business rules: `docs/requirements/model-kit-taxonomy-audit.md`. Apply approved rows via `scripts/apply-taxonomy-approved.php`, then `products:push-model-kit-tags` (T&S rows: `products:storefront-push-department`).
- **`php artisan products:model-kit-series-audit`** — series-only audit: stored ERP `series` vs title/subline inference (`ModelKitSeriesCatalog`); grouped by `mk:series:*` shelf; optional `--series=gundam_unicorn` for one bucket at a time. Writes `storage/app/model-kit-series-audit.md` + apply template. Apply via **`php artisan products:model-kit-series-apply`** after saving `storage/app/model-kit-series-approved.json`. See `docs/requirements/model-kit-series-audit.md`.
- **Evangelion character titles** (Asuka Shikinami, Rei Ayanami, Mari Makinami, Shinji/Kaworu, plug suit) infer **series + franchise Evangelion** and `mk:line:evangelion` even when the title omits the word Evangelion. A leftover **brand** of Armored Trooper Votoms must not keep those kits on the Votoms shelf.

APIs: `GET /api/v1/products/taxonomy/verifications`, `GET /api/v1/products/taxonomy/filter-options`,
`GET /api/v1/products/taxonomy/summary`,
`PATCH /api/v1/products/taxonomy/verifications/{id}/approve`,
`POST /api/v1/products/taxonomy/verifications/bulk-approve`,
`POST /api/v1/products/taxonomy/verifications/bulk-update`,
`POST /api/v1/products/taxonomy/verifications/research`,
`GET /api/v1/products/taxonomy/export`, and
`POST /api/v1/products/taxonomy/research` (full catalog; UI uses selected research instead).

---

## Tab: Products (main grid)

### Global actions

- **Saved views** — save the current filters, search, sort, and **visible columns** under a name (`products-saved-views`). Views are **shared for every operator** (`GET/POST /api/v1/products/saved-views`, `PUT/DELETE /api/v1/products/saved-views/{id}`). Choose a view to apply it (filters + columns); **Update** overwrites the selected view with the current filters and column set; **Delete** asks for confirmation and removes the named view for everyone (the grid is unchanged). Saving a name that already exists overwrites that shared view. The first load after this change uploads any leftover browser-only views (`products:saved-views:v1`) that are not already on the server. Older views without a column set restore the default columns (all optional columns except **Cost**).
- **Reset filters** — restores every list filter, search mode, sort (`received_date` desc), per-page (200), and **visible columns** to defaults, including **Only reorder ≥ 1**, store-preorder visibility, hidden URL-only fields (workshop shelf, not-arrived min, landed-cost flags), and query params such as `purchase_order_uuid` / `filters_from=url` (`data-testid=products-reset-filters-button`).
- **Refresh** — re-fetches current page/filters without clearing them.
- **Export filtered results** — downloads a UTF‑8 BOM catalog CSV of **all** products matching the active list filters across every page (`data-testid=products-export-filtered-button` → **`GET /api/v1/products/export/filtered`** with the same query params as the list). The CSV retains legacy Main Type/Type and adds all eight canonical taxonomy columns.

### Search

- **Single** — single text field (`SKU`, `barcode`, `description` server-side semantics per API). Does **not** refetch on every keystroke: waits **750ms** after typing stops, or searches immediately on **Enter** / leaving the field.
- **Bulk** — monospace textarea (**one token per line**); server receives `search_terms` array; union of matching products (see `splitBulkSearchTerms`, max parsed lines guarded). Displays count of parsed lines · “union of matches”. Buttons: **Clear**, **Search list** (`products-bulk-search`).

### Multi-select filters (from `GET /api/v1/products/filter-options`)

All catalog dropdowns load distinct values from the ERP `products` table on page load (and after create/bulk updates). Saved selections that are no longer in the option list are dropped. Nullable taxonomy/vendor fields include **(empty)** when any product has a blank value (`__empty__`).

- **Department** — `products-filter-department` (`departments[]`).
- **Manufacturer** — `products-filter-manufacturer` (`manufacturers[]`). Taxonomy (who made the product). Distinct from **Vendor** (who we buy from); they can differ (e.g. Stedi tools purchased from Dspiae).
- **Franchise** — `products-filter-franchise` (`franchises[]`).
- **Product line** — `products-filter-product-line` (`product_lines[]`).
- **Subline** — `products-filter-subline` (`sublines[]`).
- **Grade** — `products-filter-grade` (`grades[]`).
- **Scale** — `products-filter-scale` (`scales[]`).
- **Series** — `products-filter-series` (`series_values[]`).
- Legacy **Main type** / **Type** remain accepted on the API, but are hidden from the Products grid. Inventory-by-type drill-down uses **`departments[]`** plus **`product_lines[]`**, **`workshop_shelves[]`**, **`grades[]`**, **`sublines[]`**, or leftover **`types[]`**.
- **Vendor**
- **PO** — all purchase orders from the same filter-options payload (not the paginated PO list). Filters catalog to SKUs appearing on chosen purchase orders (`purchaseOrderUuids` query param family). Dropdown rows are **two lines**: line 1 = vendor · item count · short PO id; line 2 = status + dates (`Received …`, or `Not arrived · ETA … · ordered …`, or `created …` for drafts). Search matches both lines.
- Opening `/products?purchase_order_uuid={uuid}` (including the PO detail **View products in grid** shortcut) overrides the saved PO selection and loads the grid for that purchase order.
- **PO novelty** (when PO filter used): options from filter-options `po_novelty` (`products-filter-po-novelty`).

### Add product

`AddProductForm` posts **`POST /api/v1/products`** and is reused by the inventory-check resolve dialog. **Vendor** uses existing vendor options; **Department**, **Manufacturer**, **Franchise**, and **Product line** are typed combo fields backed by `GET /api/v1/products/filter-options`. Legacy `main_type` is still stored for storefront compatibility.

### Other filters

- **Missing info** — options from filter-options `missing_info` (same vocabulary as `ProductsIndexRequest`: `ok`, `pdp_description`, `pdp_images`, `barcode`, `selling_price`, `handle`, `not_ready`, `available_zero`, `maintain_empty`).
- **Ready** dropdown from filter-options `ready` (`products-filter-ready`).
- **Published** dropdown from filter-options `published` (`products-filter-published`; query param `published=all|published|not_published` on **`GET /api/v1/products`**; filters `products.published_on_shopify`).
- **Product flags** — multi-select from filter-options `product_flags` (`products-filter-product-flags`): **Urgent**, Critical, Discontinued, Hazardous shipment; sent as `product_flags[]` on **`GET /api/v1/products`**. Multiple selections use **OR** (match any selected flag).
- **Shipment** — multi-select from filter-options `shipment_methods` (`products-filter-shipment-methods`); sent as `shipment_methods[]` on **`GET /api/v1/products`**. Multiple selections use **OR** (match air **or** sea).
- **Archived** dropdown from filter-options `archived` (`products-filter-archived`; query param `archived=active|all|archived`; legacy `include_archived=1` maps to **all**).
- **Store preorders** dropdown from filter-options `store_preorder` (`products-filter-store-preorder`; query param `store_preorder=exclude|open|all`). **Default is `exclude`** (open store-preorder products are hidden). Use **Open store preorders only** to find them. Open rows show a **Store preorder** badge.
- **Available** — min/max integer inputs (`products-filter-available-min`, `products-filter-available-max`); sent as `available_min` / `available_max` on **`GET /api/v1/products`** (inclusive range on `available_qty`).
- **Not arrived**, **Reorder** — free-text numeric filters (parses non-negative ints client-side helper).
- **Selling price** — min/max decimal inputs (`products-filter-selling-price-min`, `products-filter-selling-price-max`); sent as `selling_price_min` / `selling_price_max` on **`GET /api/v1/products`** (inclusive range on `product_selling_prices.selling_price`; products without a price are excluded when either bound is set).
- **Only reorder ≥ 1** checkbox — tightening filter on reorder column logic (`reorder_gt_one=1`).
- **Per page** — `25 … 1000`.

### Sorting

`ProductsTable` exposes sortable columns matching `ProductSortKey` (**SKU, barcode, description, Urgent (`is_urgent`), taxonomies, landed cost, received date, selling price, totals, available, hold, demand, maintain, not_arrived, reorder**, …); toggling re-hits **`GET /api/v1/products`** with sort params. **Urgent** is a second header under **Product**; first click is **desc** (urgent rows first, after the no-maintain pin).

Rows with **no maintain qty** (`products.maintain_qty` is null) stay **first** in the grid (before the selected sort, including later pages). Those rows use an amber highlight, a **No maintain** badge, and an amber Maintain cell. A count chip on the toolbar reports how many of the current page are missing maintain. `0` is a set value and is not flagged.

The taxonomy columns (**department**, **manufacturer**, **franchise**, **product line**, **grade**, **scale**, **series**) are visible by default. **Columns** (`products-column-picker`) toggles any optional column (taxonomy, vendor/pricing, inventory, status). **Hide taxonomy** / **Show cost** remain shortcuts for those groups. Product, checkbox, and thumbnail stay visible. The last session’s column set is stored on `page_state:products`; named saved views store their own `visibleColumns`. Legacy main type/type stay off the grid.

### Row model (fields users see / edit)

Key row fields (`ProductRow` type): **thumbnail** (first PDP image, 96×96 in-flow cell right of the row checkbox; click opens Info), SKU, barcode, description, handle, **department** / **manufacturer** / **franchise** / **product_line** / **grade** / **series** / **scale**, vendor, **archived**, **published_on_shopify**, **is_ready**, **latest_arrival**, **is_critical**, **is_urgent**, **is_discontinued**, **is_hazardous_shipment**, **shipment_method** (`air` | `sea` | null), **`latest_*_cost`**, **received_date**, selling price snapshot, PDP flags (**has_description**, **plamod_image_count**), **`thumbnail_url`**, **total_ordered**, **shopify_orders_count**, **available**, **hold**, **`sold_4w`**, **maintain**, **not_arrived**, **reorder**. **Total sold** renders ERP-derived sold units plus `({shopify_orders_count})`; clicking the count opens **`ProductDemandDetailDialog`** to the existing recent Shopify lines. Cancelled / voided Shopify orders are excluded. **Not arrived** sums PO line **qty ordered** until the PO has a **fully on shelves date** (`fully_on_shelves_date` is null), so received-but-not-shelved stock remains counted. The **Include draft POs** checkbox toggles `not_arrived_include_draft_orders` (draft = no ordered/shipped/received dates). **Reorder** uses the same not-arrived basis as the list request.

- **Sold 4 wk** — read-only rollup (units sold in rolling **28 days**: shopify + assumed); column header label **4 wk sold**; sort key **`demand`**; click opens **`ProductDemandDetailDialog`** → **`GET /api/v1/products/{id}/demand`** (`lines_page`, `lines_per_page` for recent lines; weekly rollups show **all weeks** in the 365-day window including zeros). Shopify order lines from **cancelled** orders (**`cancelled_at`** or **`VOIDED`** financial status) are excluded from rollups, the recent-lines list, and the total-sold order count.

### Inline edits (single row)

The table wires many cells to PATCH endpoints (IDs are product UUID):

- Metadata / SKU / description / taxonomy → **`PATCH /api/v1/products/{id}`** payload families.
- **Available** qty → **`PATCH .../available`** (total on-hand, including held units).
- **Hold** qty → **`PATCH .../hold`** (`hold_qty`; must be `0…available`). Units withheld from Shopify push; subtracted only at inventory push/export to Shopify (`shopify_push_qty = available - hold`).
- **Maintain** qty → **`PATCH .../maintain`** (paired domain column).
- **Ready** checkbox → **`PATCH .../ready`**.
- **Latest arrival** checkbox → **`PATCH .../latest-arrival`**.
- **Urgent / Critical / Discontinue / Hazardous shipment** — checkboxes under the product name (SKU/barcode/handle block); not separate columns. **`PATCH .../urgent`** (`is_urgent`), **`PATCH .../critical`** (`is_critical`), **`PATCH .../discontinue`** (`is_discontinued`), **`PATCH .../hazardous-shipment`** (`is_hazardous_shipment`, default false). Checking **Discontinue** or **Hazardous shipment** opens a confirm dialog before saving; unchecking applies immediately. **Urgent** and **Critical** save immediately with no confirm. Updates are **optimistic** (no full list reload; the grid stays mounted).
- **Shipment** — dropdown under the product name (**—**, **Air**, **Sea**); **`PATCH .../shipment-method`** (`shipment_method` nullable). Same optimistic in-row update (no table teardown).
- **List refresh** — filter/sort/page changes show a light **Refreshing…** overlay on the grid instead of replacing the table with a blank loading state.
- **Sticky column headers** — Product / qty / taxonomy headers stay pinned under the app nav while scrolling the page (`top: var(--app-nav-height)`). The Products tab card does not use `overflow-hidden`, which would cancel sticky.

### Drawer: “Info” (PDP bundle)

Opens **`PlamodDrawer`** (**Info** UX): product identity + **Get product info** (dispatches **`SyncProductInfoJob`** via **`POST /api/v1/products/{id}/product-info/sync`**, HTTP **202**) + carousel / description / attrs / selling price + **preferred description source** PATCH + **Manual description** card (seeded from the current Other text; **Save & use** writes `manual_description_html` onto `product_external_contents.source=other`, including an intentional **blank** body so Shopify export does not fall back to HLJ/competitor text) + **`ProductInfoPhotosPanel`** (isolated gallery: upload / On/Off / drag / hide-source do **not** reload `GET /product-info` or reset the hero). **Use this** / **Save & use** PATCH preferred description and update local contents only. Photo grid thumbnails load via **`GET /api/v1/product-assets/{id}/thumb`** (max width 320px JPEG, cached on disk); hero preview uses **`/view`** (full size) and keeps the current selection unless that photo is no longer visible. **On/Off (Shopify export)** still physically reorders (exporting first, disabled last): badge/order update immediately; **`PATCH /product-assets/{id}/shopify-enabled`** plus background **`PUT /products/{id}/assets/order`** (latest-wins, grid stays clickable). Hide-source chips and **Disable exact duplicates** use one **`PATCH /products/{id}/assets/shopify-enabled`** (`ids[]` + `shopify_enabled: false`) — opening the drawer does **not** auto-disable hidden sources. In-flight photo writes show **Saving photo changes… (N in progress)** until every PATCH/PUT finishes. Manual image delete still confirms (`DELETE /api/v1/product-assets/{numericId}` — only **`manual_upload`**).

Detailed backend semantics: `docs/requirements/pdp-content-ingestion.md`, `docs/requirements/handle-and-shopify-content-export.md`, and **`docs/features/backend/system-catalog-services-and-http.md`**.

### Drawer: PO lines

**`ProductPoLinesDrawer`**: **`GET /api/v1/products/{id}/po-lines`** for operational context (“which PO touched this SKU” presentation). The right-side panel expands to `max-w-7xl`; its table fits all columns in common desktop widths and remains horizontally scrollable on narrower viewports. Table columns: PO link, vendor, ordered/received dates, **qty ordered**, qty shipped, qty received, unit/ship/surcharge/landed costs.

### Selection & bulk toolbar

Supports **page selection** vs **Select all matching** (cache key **`selectionScopeKey`**—invalidates selection when filters/sorts change materially).

Bulk actions (confirmation dialogs vary by action — see **`ConfirmDialog`**, **`BulkUpdateDialog`**):

| Action                                        | API / behavior summary                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Bulk delete**                               | `POST /api/v1/products/bulk-delete` — confirm required in UI                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| **Bulk archive**                              | `POST /api/v1/products/bulk-archive`                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| **Bulk update** (`BulkUpdateDialog`)          | `POST /api/v1/products/bulk-update` — published Shopify, archive, SKU fields subset, qty fields, **urgent / critical / discontinued / hazardous shipment**, **shipment_method**, …                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| **Bulk export selected** (`BulkExportDialog`) | See **Export formats** row below                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| **Bulk recrawl** (`BulkRecrawlDialog`)        | `POST /api/v1/products/recrawl/selected` with **image** sources **`bandai`**, **`hlj`**, **`gundamplanet`**, **`newtype`**, **`gundamhangar`**, **`argama`**, **`cool_dragon`**, **`plamod`**, plus **per-site price** keys from `config/price_research.php` (`argama_hobby`, `panda_hobby`, `canada_computers`, `canadian_gundam`, `hobby_bee`, `hobby_wholesale`, `meeplemart`, `hobby_sense`, `gundam_hangar`, `cool_dragon_hobby`, `aliexpress`). Dialog defaults: usual image set on; all CAD price sites on; Argama/Cool Dragon **images** and AliExpress **off**. Legacy source **`competitor_price_research`** still means all price sites (PO “Crawl new”).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| **Push to Shopify** (`BulkPushShopifyDialog`) | **`POST /api/v1/products/shopify-push/preview`** then **`POST /api/v1/products/shopify-push/selected`** — checkbox matrix: **`info`**, **`images`**, **`quantities`**, **`price`**, **`publish_status`**, **`sales_channels`**. Preview is **local-only** (ERP + Shopify mirror tables; no live Admin SKU search, no tunnel HTTP probe) so large selections stay under the browser timeout. It returns create/update/skip counts, scope/tunnel/location warnings. If any selected row is an **open store preorder**, the dialog warns and **`window.confirm`s** before queueing (deposit price + Pre-order page only). Queue returns **202** + **`batch_id`** → **`/sync-progress`** (`push_selected_products_shopify` batch). Uses same `productSet` upsert as PO push; sellable qty = available − hold, except open store preorders use remaining cap and the **deposit** as Shopify price. **Image updates on existing products:** when **images** is checked, Shopify media is cleared then ERP `shopify_enabled` assets are uploaded; when **images** is unchecked but ERP has **zero** `shopify_enabled` images, stale Shopify media is still removed (typical bulk matrix: info/qty/price/publish without images). When **images** is checked, the job **auto-starts** the Cloudflare tunnel if needed (`ShopifyImageTunnelLeaseService`), **verifies each signed image URL is HTTP-reachable**, waits until Shopify media status is **READY** (poll up to 120s), then **restores** the prior running/stopped state. Failed media (`FAILED`) fails the batch item. Image assets are renamed to SEO-friendly filenames immediately before upload. New Shopify products require **info + price** toggles. **Tags** are sent only when **info** is checked or the product is being created; images-only updates omit `tags` so Shopify keeps existing tags (including `sp:store-preorder`). |
| **Create draft PO** (when handler present)    | `POST /api/v1/purchase-orders/drafts/create-from-products` — one shared catalog vendor keeps that PO vendor; **mixed or blank vendors** set the header to **Other/multi** and add every selected line (line `vendor` keeps the product vendor). Lines use **`latest_unit_cost`** (fallback **`latest_landed_unit_cost`**); PO header **`product_total`** / **`shipping_total`** (CAD) are estimated from lines (shipping ≈ last landed − unit × qty)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |

After a successful Shopify upsert of a **model-kit** product (bulk push, department push, PO inventory push, store-preorder Shopify sync), the ERP **pokes** the model-kit storefront index cache. The poke queues a unique delayed job that **recreates** the Shopify theme file `snippets/ovs-model-kit-index-cache.liquid` so the next collection visitor hydrates filters instantly. ERP-only edits do not poke — Shopify still has the old product until it is pushed. T&S upserts do not poke (those shelves filter the cards already on the page). Nightly rebuild: `php artisan storefront:model-kit-index-rebuild`. See [maintenance.md](maintenance.md#model-kit-storefront-index-cache).

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

Several filter/search/toggle blobs saved per admin workflow (inspect `STATE_KEY`-style refs in **`ProductsPage.vue`**) — restoring on mount for continuity across reloads within the same browser profile, including **`visibleColumns`**. Named **saved views** are a shared list (`product_saved_views` / `/api/v1/products/saved-views`) so every operator sees the same named filter/sort/column sets. The last ad-hoc page state still stays in this browser until they apply a view or reset.
