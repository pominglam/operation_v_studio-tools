# Purchase orders (`/purchase-orders`, `/purchase-orders/:id`)

**List page:** `resources/js/pages/PurchaseOrdersPage.vue`  
**Detail page:** `resources/js/pages/PurchaseOrderDetailPage.vue`

---

## List page — history & import

### Filters & sorting

Persisted snapshot key: **`purchase-orders:history-filters:v1`** (`clearPageState` / `savePageState`).

- Multi-select **vendor** facets combined with seeded defaults (**Plamod, Dspiae, Stedi, Gaahleri, MSMN, PM, JS**) merged with **`GET /api/v1/purchase-orders/filter-options`**.
- Multi-select **status** chips: Draft, Ordered, Shipped, Received, **On shelves**.
- **Sort** presets: **`created | ordered | received`** ascending/descending.

Derived **status labels** mapping internal enum → human copy (`poStatusLabel`).

**History table columns** (after filters): ID (+ supplier order ID subline), **Status**, **Shipment**, **Created** / **Ordered** / **Estimated arrival** / **Received** / **On shelves**, **Vendor**, **Items**, **Product total**, **Shipping total**, **Surcharge total**, **Total**.

| Column | API / logic |
| --- | --- |
| **Shipment** | `shipment_method` on **`purchase_orders`** (`air` \| `sea` \| null). Set on import/create form, PO header edit, or inferred once from line products when unset (unambiguous air-only or sea-only). |

### Pagination

Loads **`GET /api/v1/purchase-orders`** with query params aligning to filters/sorts (see network layer in script).

### “Create / Import” card

Single multipart submission **`POST /api/v1/purchase-orders/import`** containing:

| FormData field | Source |
| --- | --- |
| **`file`** | Required CSV or XLSX picker (`.csv`, `.xlsx`) |
| **`vendor`** | Datalist-backed text (seeded vendors merged from filter API + defaults) |
| **`supplier_order_id`**, date fields (**`ordered_date`**, **`shipped_date`**, **`estimated_arrival_date`**, **`received_date`**, **`fully_on_shelves_date`**) | Optional structured metadata |
| **`product_total`**, **`shipping_total`**, **`surcharge_total`** | Decimal text inputs (**CAD** labeling for product total in UI copy) |
| **`product_total_includes_fees`** | Checkbox (Dspiae/Stedi only): treat product total as **total paid CAD**; server splits product vs shipping using invoice HKD product/freight ratio |
| **`notes`** | Optional free text |
| **`shipment_method`** | Optional **`air`** or **`sea`** (stored on PO header) |

Selecting a CSV may **auto-sniff vendor** (`sniffVendorFromCsv`) before upload to pre-fill **Vendor** (DSPIAE / Stedi heuristics in first 4KB).

### Dspiae / Stedi — PM broker invoice preview

When **Vendor** is **Dspiae** or **Stedi**, the primary button reads **Preview import**. Clicking it calls **`POST /api/v1/purchase-orders/import/preview`** (same FormData fields as import, minus PO UUID linkage) and opens **`PoImportPreviewDialog`** (`resources/js/components/purchaseOrders/PoImportPreviewDialog.vue`).

Preview table columns: **Item · SKU · Qty · Unit (HKD) · Line (HKD) · Unit (CAD) · Line (CAD)** plus footer totals and summary (**product total CAD**, **shipping CAD**, **vendor total HKD**, implied **FX** when product total CAD is supplied).

**Confirm import** reuses **`POST /api/v1/purchase-orders/import`** with the same file/metadata (no second upload picker).

PM broker layout (`.xlsx` or `.csv`): preamble rows skipped; header **`Customer | Item | SKU | Qty | unit price | Amount`**. Parser tries PM layout first for Dspiae/Stedi vendors, then falls back to native vendor CSV formats.

Other vendors keep the direct import path (no preview dialog).

Success renders link to **`/purchase-orders/{purchase_order_uuid}`** plus **items**, **lots**, **`shipping_per_unit`** summary.

**Import blocked** state enumerates **`importIssues`** as bullet list requiring operator correction before retry.

---

## Detail page (`/purchase-orders/:id`)

### Header / metadata editor

Loads **`GET /api/v1/purchase-orders/{uuid}`** (`PurchaseOrderResource` projection).

Supports inline edit + save for PO-level fields (**vendor_currency_code**, **fx** fields, **`supplier_order_id`**, dates, monetary totals **`product_total`, `shipping_total`, `surcharge_total`, `vendor_product_total`**, **`notes`**, **`is_done`** flag semantics).

### Status presentation

Shows computed **lifecycle status**: **draft**, **ordered**, **shipped**, **received**, **on_shelves** with human labels (`draftStatusLabel`).

### Re-import / import more PO document

Controls (same card on detail page):

| Field / control | Notes |
| --- | --- |
| **Product total (CAD)** / **Total paid (CAD)** | Optional; used for FX on new lines when vendor currency is not CAD |
| **`product_total_includes_fees`** | Checkbox when PO vendor is **Dspiae** or **Stedi**: treat entered CAD as total paid; server splits product vs shipping using invoice HKD ratio |
| **Shipping total** | Optional; disabled when includes-fees is checked |
| **CSV / XLSX file** | `.csv`, `.xlsx` |
| **Clear PO receipt data first** | Replace (**Re-import**) only: purge PO-linked lots/movements and clear **`qty_received`** before replacing lines |
| **Re-import** | **`import_mode=replace`** + **`purchase_order_uuid`** on **`POST /api/v1/purchase-orders/import`** |
| **Import more** | **`import_mode=append`** — merges rows into an existing line when the same **`product_id`** is already on the PO; otherwise adds lines. **Within-file duplicate SKUs** are merged before insert (qty summed; **`unit_cost`** qty-weighted average). Header **`product_total`**, **`shipping_total`**, **`vendor_product_total`**, and **`surcharge_total`** combine with the new import (after all-fees CAD split when checked). |

**Dspiae / Stedi** on detail page: **Preview re-import** / **Preview import more** call **`POST /api/v1/purchase-orders/import/preview`** (includes **`purchase_order_uuid`** + **`import_mode`**) and open **`PoImportPreviewDialog`**. **Confirm** runs the same import POST with the already-selected file.

Typical two-invoice workflow: import invoice 1 from list page → open PO → **Import more** with invoice 2 (preview + includes-fees if needed). Header totals accumulate automatically; **`product_total` (CAD)** is also recomputed from all line unit costs after import.

**Duplicate SKU lines (maintenance):** New imports merge duplicate SKUs automatically. To fix historical POs, run **`php artisan purchase-orders:dedupe-lines`** (dry-run) or **`php artisan purchase-orders:dedupe-lines --execute --yes`** (optional **`--po={uuid}`**). Merges by **`product_id`**, sums qty ordered/shipped, applies inventory-aware **`qty_received`** merge, qty-weighted **`unit_cost`**, reassigns PO-linked lots, then recomputes PO derived totals.

Other vendors: direct re-import / import more (no preview dialog).

### Line grid (per SKU)

Columns include SKU, vendor, qty **ordered/shipped/received**, **unit_cost** editable, linkages to **`product_id` UUID**, optional **available / maintain / not_arrived / reorder** aggregates, **`latest_landed_unit_cost`**, **`selling_price`**, multiplier display, etc.

Interactions:

| User action | API |
| --- | --- |
| Edit qty ordered / shipped / received | PATCH item endpoints (**`/api/v1/purchase-order-items/{id}`** or bulk patch path) |
| Edit barcode column (when surfaced) | product-level barcode patch proxied via item row workflows |
| Edit unit cost | item PATCH validations |
| **Bulk update selected rows** (`BulkUpdatePoItemsDialog`) | **`PATCH /api/v1/purchase-orders/{uuid}/items`** with selected IDs payload |

### Apply received quantities → available inventory

- Button triggers **`POST /api/v1/purchase-orders/{uuid}/apply-received-to-available`**.
- Backend sums positive **`qty_received`** per **`product_id`**, increments **`products.available_qty`** transactionally — UI shows summarized counts / errors (`applyReceivedSummary` messaging).

### Apply inventory-check snapshot → qty received on PO

- User picks an **`inventory-check` session UUID** (`GET`-listed with pagination helpers in page script).
- Confirmed apply posts **`POST /api/v1/purchase-orders/{uuid}/apply-inventory-check`** with chosen check id.
- **Behavior:** resets PO receipt artifacts per service (removes movements/lots, clears **`qty_received`**) then overlays quantities aggregated by **trimmed SKU** from check rows; **`ProductLatestCostCacheService`** recomputes impacted SKUs; UI renders **warnings** (e.g., SKUs counted in CSV but absent on PO) inside dedicated panel.

### Draft workflow utilities

Available when PO is drafting / mixed states per UI guards:

| Feature | Endpoint |
| --- | --- |
| Export order CSV | **`GET /api/v1/purchase-orders/{uuid}/draft-lines-export`** — **SKU**, **Product Name**, **Qty**, **CAD** unit/line first; **Stedi / Dspiae** add **HKD** columns after CAD. No barcode/shipping; **qty 0** omitted. FX: current PO or latest PO with **HKD** (broker vendors always use HKD for FX lookup even when PO header says CAD). HKD from `vendor_unit_cost` or CAD÷FX. |
| Paste SKUs textarea → append lines | **`POST /api/v1/purchase-orders/{uuid}/draft-products`** validating multi-line SKU input |
| Created from Products grid | **`POST /api/v1/purchase-orders/drafts/create-from-products`** — sets line **`unit_cost`** from **`latest_unit_cost`** and fills header **`product_total`** / **`shipping_total`** (CAD estimates from cached costs × reorder qty) |

### Workflow checklist ribbon

Keyed checklist toggles mirrored from server field **`workflow_checklist`** persisted via **`PATCH /api/v1/purchase-orders/{uuid}/workflow-checklist`** storing JSON blob with keys exactly as labeled in script:

1. **`import_po`**
2. **`crawl_desc_image_price`** — **Plamod only** for crawl requirement; **Skip** (confirm on Plamod) or **Crawl new** (confirm when vendor is not Plamod). **`crawl_desc_image_price_skipped`** stored when skipped.
3. **`select_and_arrange_product_images`** — manual: open each product on Products, toggle Shopify images and drag to reorder (Plamod drawer). Not auto-verified. **Defer** checks the step off for this PO when photos will be added later (`select_and_arrange_product_images_deferred` in checklist JSON); unchecking the step clears defer.
4. **`set_selling_price`** — manual approval only (never auto-checked on load/Re-verify); check after reviewing **Set/review** preview
5. **`ensure_all_products_have_barcode`**
6. **`export_to_shopify_get_handles`**
7. **`import_handle_only`**
8. **`update_product_available_with_shopify_current_inventory_quantity`**
9. **`mark_published_on_shopify`** — ERP **`published_on_shopify`** for all PO products (push sets Shopify ACTIVE + sales channels)
10. **`mark_latest_arrival`** — ERP **`latest_arrival`** for PO products **except `main_type` tools** (push adds **`latest arrival`** tag; tools stay off homepage unless toggled on Products)
11. **`import_product_available_quantity`** — **Push to Shopify — Latest Arrivals order** (full product state; not a CSV import)

Each checkbox toggling hits API with optimistic UI rollback on HTTP errors (`checklistBusy`/`checklistError`).

**Re-verify** (`POST /api/v1/purchase-orders/{uuid}/workflow-verify`) runs step checks and auto-checks completed steps (never unchecks).

Per-row action buttons (right side of each checklist line):

| Step key | Button | Endpoint |
| --- | --- | --- |
| `crawl_desc_image_price` | Crawl new / **Skip** | `POST .../workflow-actions/crawl-new-products`. **Skip** checks the step (Plamod: confirm). **Crawl new** on non-Plamod: confirm. Re-verify auto-checks crawl only for **Plamod** when PDP exists and step was not skipped. |
| `select_and_arrange_product_images` | Review images / **Defer** | **Review images** opens **`/products?purchase_order_uuid=…`** in a **new browser tab** (manual image pick/order in Plamod drawer). **Defer** (confirm) checks the step and sets **`select_and_arrange_product_images_deferred`** so the PO workflow can continue without curating every product first. |
| `set_selling_price` | Set/review | `GET .../workflow-actions/set-prices/preview` then confirm → `POST .../workflow-actions/set-prices`. Operator checks the step manually after approving the preview (not auto-verified). |

**Set/review preview dialog** (`PoWorkflowSetPricesDialog`): **Set/review** loads preview first. Sections (in order): **New prices** (no current selling price), **Price updates** (raises only — formula higher than current), **No price change** (already matches formula, or **keeping current** when formula would be lower), **Missing landed cost** (skipped). Within each section, **new-on-PO products appear before existing** SKUs. **Apply prices** writes only new + raise rows; **Cancel** closes without changes.

| `export_to_shopify_get_handles` | Export | `GET .../workflow-actions/export-shopify-content/preview` then **Push to Shopify** → `POST .../workflow-actions/export-shopify-content/push` |

**Export to Shopify preview dialog** (`PoWorkflowExportShopifyDialog`): **Export** loads preview for **products on this PO without a stored handle** (includes re-orders from earlier POs). Fixed export type **Shopify content (images + description)** — same field set as bulk **`shopify_content`** CSV (includes **`available_qty`** on create). **Push to Shopify** calls Shopify Admin **`productSet`** (requires **`write_products`** + **`write_inventory`** OAuth scopes and a resolvable inventory location). When **`published_on_shopify`** is true, also publishes to all sales channels via **`publishablePublish`** (**`read_publications`** + **`write_publications`**). Table columns: SKU, Product, Handle (blank), Status. Handles returned from Shopify are saved on **`products.handle`**. Image tunnel recommended for signed image URLs; without tunnel, products push without images. **Re-verify** auto-checks **`export_to_shopify_get_handles`** when **every product on the PO** has a non-empty **`products.handle`** (also runs after a successful push).
| `import_handle_only` | Pull handles | `GET .../workflow-actions/pull-handles/preview` then **Pull handles** → `POST .../workflow-actions/pull-handles` |

**Pull handles preview dialog** (`PoWorkflowPullHandlesDialog`): **Pull handles** loads preview for **products on this PO without a stored local handle** (includes re-orders). If **`pull_count`** is zero, shows a clear message that all products on the PO already have handles. Otherwise lists SKU, Product, local handle (blank), and **mirror handle** from the current Shopify mirror (best-effort before sync). **Pull handles** confirms a read-only Shopify product sync, then copies handles by SKU into **`products.handle`**. SKUs not found in Shopify are listed after the run.
| `update_product_available_with_shopify_current_inventory_quantity` | Prepare + Apply received | `POST .../workflow-actions/prepare-inventory` validates **qty received** on all lines. If **`products`** and **`inventory_levels`** mirror syncs both completed within **1 hour** (`SHOPIFY_PO_PREPARE_MIRROR_FRESHNESS_SECONDS`, default 3600), **skips** Shopify pull. If stale, returns **`mirror_stale_confirmation_required`**; SPA prompts whether to pull. Confirm → same endpoint with **`pull_shopify: true`** refreshes **inventory levels for PO SKUs only** (not full store). Decline → proceeds with existing mirror data. SKUs missing from mirror → 422 with hint to run Maintenance / `shopify:sync products`. Then `POST .../apply-received-to-available`. SPA uses no client timeout on Prepare. |
| `mark_published_on_shopify` | **Mark published** | `POST .../workflow-actions/mark-published-on-shopify` — sets **`published_on_shopify`** true for **all** PO products (ERP only until push). |
| `mark_latest_arrival` | **Clear old latest** + **Mark latest** | **Clear old latest:** `POST .../workflow-actions/clear-stale-latest-arrival` (same as before). **Mark latest:** `POST .../workflow-actions/mark-latest-arrival` — sets **`latest_arrival`** true for PO products **except** `main_type` **tools** (response includes **`skipped_tools`**). Legacy combined endpoint **`mark-latest-arrival-published`** still exists. |
| `import_product_available_quantity` | **Push to Shopify** | Preview + **`POST .../workflow-actions/push-inventory`** — full ERP → Shopify sync per PO product via **`productSet`**: title, description, images (when tunnel on), tags (incl. **`latest arrival`** when ERP flag set), price, publish status, and **sellable** inventory (`shopify_push_qty = max(0, erp_available - erp_hold)`). **Preview** (`PoWorkflowPushInventoryDialog`) table columns include **Available**, **Hold**, **Push qty**, and current **Shopify qty**; **Copy product names** copies preview titles one per line (Latest Arrivals sort order). When **`published_on_shopify`** is true, **`publishablePublish`** to all publications. **Preview** sorts products on **this PO only** (CCS toys → Sazabi bust → PG → Mega → MG (MGEX first) → RE → Full Mechanics → RG → HGUC → HG → SD/BB/EX-Standard → Kun DX → Macross → 30MM (Armored Core first) → 30MF → 30MS → 30MP → Figure-rise → Entry Grade → Pokemon → Keroro → Option parts → Action base → System base → LED → Option parts set Gunpla, newest within each grade). After a successful push (no failures), reorders the **Latest Arrivals** Shopify collection when **`SHOPIFY_LATEST_ARRIVALS_COLLECTION_GID`** is set: **received POs only** by **`received_date` desc** (unreceived POs ignored; products on multiple received POs count under their **newest received** PO only), then the **same grade order within each PO** (`LatestArrivalCatalogOrderService` + **`collectionReorderProducts`**; collection must be **manual** sort). Config: **`config/latest_arrival.php`**. Barcode scan override import remains under the export card (**Import product quantity**). |

### Auxiliary modals reused from Products domain

- **`ImportHandlesCard`** — same handle importer as Products tab (**`POST /api/v1/products/import-handles`**) surfaced for PO-aligned workflows.
- **`ImportInventoryQuantityOverrideCard`** — quantity override importer.
- **Bulk Shopify export / PDP recrawl dialogs** behave like **`ProductsPage`** equivalents but seed selected IDs from **`poProductUuids` computed unique list** derived from PO line **`product_id` UUID references.
- PDP batch queue success navigations push **`sync-progress`** with **`batch_id`**, mirroring **`ProductsPage`**.

### Delete PO

- Guarded **`DELETE /api/v1/purchase-orders/{uuid}`** — confirm destructive dialog in UI (`PurchaseOrderDeleteService` validations apply server-side).

### PO lines shortcut drawer

Opens **`ProductPoLinesDrawer`** for selected product UUID contextual navigation without leaving PO screen.
