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
| **Import more** | **`import_mode=append`** — adds lines, keeps existing lines; **combines** PO header **`product_total`**, **`shipping_total`**, **`vendor_product_total`**, and **`surcharge_total`** with values from the new import (after all-fees CAD split when checked) |

**Dspiae / Stedi** on detail page: **Preview re-import** / **Preview import more** call **`POST /api/v1/purchase-orders/import/preview`** (includes **`purchase_order_uuid`** + **`import_mode`**) and open **`PoImportPreviewDialog`**. **Confirm** runs the same import POST with the already-selected file.

Typical two-invoice workflow: import invoice 1 from list page → open PO → **Import more** with invoice 2 (preview + includes-fees if needed). Header totals accumulate automatically; **`product_total` (CAD)** is also recomputed from all line unit costs after import.

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
| Export draft CSV | **`GET /api/v1/purchase-orders/{uuid}/draft-lines-export`** (blob handling with filename parse) |
| Paste SKUs textarea → append lines | **`POST /api/v1/purchase-orders/{uuid}/draft-products`** validating multi-line SKU input |

### Workflow checklist ribbon

Keyed checklist toggles mirrored from server field **`workflow_checklist`** persisted via **`PATCH /api/v1/purchase-orders/{uuid}/workflow-checklist`** storing JSON blob with keys exactly as labeled in script:

1. **`import_po`**
2. **`crawl_desc_image_price`**
3. **`select_and_arrange_product_images`** — manual: open each product on Products, toggle Shopify images and drag to reorder (Plamod drawer). Not auto-verified. **Defer** checks the step off for this PO when photos will be added later (`select_and_arrange_product_images_deferred` in checklist JSON); unchecking the step clears defer.
4. **`set_selling_price`**
5. **`ensure_all_products_have_barcode`**
6. **`export_to_shopify_get_handles`**
7. **`import_handle_only`**
8. **`update_product_available_with_shopify_current_inventory_quantity`**
9. **`mark_latest_arrival_and_published_on_shopify`**
10. **`import_product_available_quantity`**

Each checkbox toggling hits API with optimistic UI rollback on HTTP errors (`checklistBusy`/`checklistError`).

**Re-verify** (`POST /api/v1/purchase-orders/{uuid}/workflow-verify`) runs step checks and auto-checks completed steps (never unchecks).

Per-row action buttons (right side of each checklist line):

| Step key | Button | Endpoint |
| --- | --- | --- |
| `crawl_desc_image_price` | Crawl new | `POST .../workflow-actions/crawl-new-products` |
| `select_and_arrange_product_images` | Review images / **Defer** | **Review images** opens **`/products?purchase_order_uuid=…`** in a **new browser tab** (manual image pick/order in Plamod drawer). **Defer** (confirm) checks the step and sets **`select_and_arrange_product_images_deferred`** so the PO workflow can continue without curating every product first. |
| `set_selling_price` | Set prices | `GET .../workflow-actions/set-prices/preview` then confirm → `POST .../workflow-actions/set-prices` |

**Set prices preview dialog** (`PoWorkflowSetPricesDialog`): **Set prices** loads preview first. Sections (in order): **New prices** (no current selling price), **Price updates** (raises only — formula higher than current), **No price change** (already matches formula, or **keeping current** when formula would be lower), **Missing landed cost** (skipped). Within each section, **new-on-PO products appear before existing** SKUs. **Apply prices** writes only new + raise rows; **Cancel** closes without changes.

| `export_to_shopify_get_handles` | Export | `GET .../workflow-actions/export-shopify-content/preview` then **Push to Shopify** → `POST .../workflow-actions/export-shopify-content/push` |

**Export to Shopify preview dialog** (`PoWorkflowExportShopifyDialog`): **Export** loads preview for **products on this PO without a stored handle** (includes re-orders from earlier POs). Fixed export type **Shopify content (images + description, no inventory)**. **Push to Shopify** calls Shopify Admin **`productSet`** (requires **`write_products`** OAuth scope). Table columns: SKU, Product, Handle (blank), Status. Handles returned from Shopify are saved on **`products.handle`**. Image tunnel recommended for signed image URLs; without tunnel, products push without images. **Re-verify** auto-checks **`export_to_shopify_get_handles`** when **every product on the PO** has a non-empty **`products.handle`** (also runs after a successful push).
| `import_handle_only` | Pull handles | `GET .../workflow-actions/pull-handles/preview` then **Pull handles** → `POST .../workflow-actions/pull-handles` |

**Pull handles preview dialog** (`PoWorkflowPullHandlesDialog`): **Pull handles** loads preview for **products on this PO without a stored local handle** (includes re-orders). If **`pull_count`** is zero, shows a clear message that all products on the PO already have handles. Otherwise lists SKU, Product, local handle (blank), and **mirror handle** from the current Shopify mirror (best-effort before sync). **Pull handles** confirms a read-only Shopify product sync, then copies handles by SKU into **`products.handle`**. SKUs not found in Shopify are listed after the run.
| `update_product_available_with_shopify_current_inventory_quantity` | Prepare + Apply received | `POST .../workflow-actions/prepare-inventory` then `POST .../apply-received-to-available` |
| `mark_latest_arrival_and_published_on_shopify` | Mark flags | `POST .../workflow-actions/mark-latest-arrival-published` |
| `import_product_available_quantity` | Import qty | Opens quantity override import card |

### Auxiliary modals reused from Products domain

- **`ImportHandlesCard`** — same handle importer as Products tab (**`POST /api/v1/products/import-handles`**) surfaced for PO-aligned workflows.
- **`ImportInventoryQuantityOverrideCard`** — quantity override importer.
- **Bulk Shopify export / PDP recrawl dialogs** behave like **`ProductsPage`** equivalents but seed selected IDs from **`poProductUuids` computed unique list** derived from PO line **`product_id` UUID references.
- PDP batch queue success navigations push **`sync-progress`** with **`batch_id`**, mirroring **`ProductsPage`**.

### Delete PO

- Guarded **`DELETE /api/v1/purchase-orders/{uuid}`** — confirm destructive dialog in UI (`PurchaseOrderDeleteService` validations apply server-side).

### PO lines shortcut drawer

Opens **`ProductPoLinesDrawer`** for selected product UUID contextual navigation without leaving PO screen.
