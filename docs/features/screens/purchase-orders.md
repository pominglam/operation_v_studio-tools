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
| **`file`** | Required CSV picker |
| **`vendor`** | Datalist-backed text (seeded vendors merged from filter API + defaults) |
| **`supplier_order_id`**, date fields (**`ordered_date`**, **`shipped_date`**, **`estimated_arrival_date`**, **`received_date`**, **`fully_on_shelves_date`**) | Optional structured metadata |
| **`product_total`**, **`shipping_total`**, **`surcharge_total`** | Decimal text inputs (**CAD** labeling for product total in UI copy) |
| **`notes`** | Optional free text |

Selecting a CSV may **auto-sniff vendor** (`sniffVendorFromCsv`) before upload to pre-fill **Vendor**.

Success renders link to **`/purchase-orders/{purchase_order_uuid}`** plus **items**, **lots**, **`shipping_per_unit`** summary.

**Import blocked** state enumerates **`importIssues`** as bullet list requiring operator correction before retry.

---

## Detail page (`/purchase-orders/:id`)

### Header / metadata editor

Loads **`GET /api/v1/purchase-orders/{uuid}`** (`PurchaseOrderResource` projection).

Supports inline edit + save for PO-level fields (**vendor_currency_code**, **fx** fields, **`supplier_order_id`**, dates, monetary totals **`product_total`, `shipping_total`, `surcharge_total`, `vendor_product_total`**, **`notes`**, **`is_done`** flag semantics).

### Status presentation

Shows computed **lifecycle status**: **draft**, **ordered**, **shipped**, **received**, **on_shelves** with human labels (`draftStatusLabel`).

### Re-import PO document

Controls:

- Replacement file picker + **Re-import** / **Append** style operations (inspect template for wording; backs onto **`POST /api/v1/purchase-orders/import`** with linkage to existing PO UUID—see controllers tests).
- **`reimportResetReceipt`** boolean (documented inline in Vue): when true for replace flows, expectation is **purge PO-linked movements/lots** + clear **`qty_received`** before reloading lines (**matches server contract** guarded in tests).

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
3. **`set_selling_price`**
4. **`ensure_all_products_have_barcode`**
5. **`export_to_shopify_get_handles`**
6. **`import_handle_only`**
7. **`update_product_available_with_shopify_current_inventory_quantity`**
8. **`mark_latest_arrival_and_published_on_shopify`**
9. **`import_product_available_quantity`**

Each checkbox toggling hits API with optimistic UI rollback on HTTP errors (`checklistBusy`/`checklistError`).

### Auxiliary modals reused from Products domain

- **`ImportHandlesCard`** — same handle importer as Products tab (**`POST /api/v1/products/import-handles`**) surfaced for PO-aligned workflows.
- **`ImportInventoryQuantityOverrideCard`** — quantity override importer.
- **Bulk Shopify export / PDP recrawl dialogs** behave like **`ProductsPage`** equivalents but seed selected IDs from **`poProductUuids` computed unique list** derived from PO line **`product_id` UUID references.
- PDP batch queue success navigations push **`sync-progress`** with **`batch_id`**, mirroring **`ProductsPage`**.

### Delete PO

- Guarded **`DELETE /api/v1/purchase-orders/{uuid}`** — confirm destructive dialog in UI (`PurchaseOrderDeleteService` validations apply server-side).

### PO lines shortcut drawer

Opens **`ProductPoLinesDrawer`** for selected product UUID contextual navigation without leaving PO screen.
