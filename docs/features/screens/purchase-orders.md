# Purchase orders (`/purchase-orders`, `/purchase-orders/:id`, `/purchase-orders/:id/beta`)

**List page:** `resources/js/pages/PurchaseOrdersPage.vue`  
**Detail page:** `resources/js/pages/PurchaseOrderDetailPage.vue`  
**Beta workspace:** `resources/js/pages/PurchaseOrderDetailBetaPage.vue` at `/purchase-orders/:id/beta`

The classic detail page remains the reliable default. The beta route is an opt-in redesign: mission-control overview, grouped Actions menu, and progressive tabs. Unimplemented writes still open classic UI. A **Try beta UI** link on classic detail and an **Edit details** link on beta keep both available. The beta chrome hides the global `AppNav` and uses the storefront `colorBar.png` at 65% width, right-aligned.

---

## List page — history & import

### Filters & sorting

Persisted snapshot key: **`purchase-orders:history-filters:v2`** (`clearPageState` / `savePageState`).

- Multi-select **vendor** facets combined with seeded defaults (**Plamod, Dspiae, Stedi, Other/multi, Gaahleri, MSMN, PM, JS**) merged with **`GET /api/v1/purchase-orders/filter-options`**.
- Multi-select **status** chips: Draft, Ordered, Shipped, Received, **On shelves**. **Default (fresh load / Reset filters):** Draft, Ordered, Shipped, Received — **On shelves** is not pre-selected.
- **Sort:** Click any history data column (except the checkbox and **Actions**) to sort via `GET /api/v1/purchase-orders?sort_by=&sort_dir=`. First click on a column sorts descending; clicking the active column toggles ascending. Default: **ordered desc**. Nullable date/money columns keep nulls last. **Status** follows workflow order (Draft → Ordered → Shipped → Received → On shelves). **Total** is product + shipping + surcharge (all-null totals last). Internal `sort_by=filter` (product/price-research PO picker) is unchanged.

Derived **status labels** mapping internal enum → human copy (`poStatusLabel`).

**History table columns** (after filters): ID (+ supplier order ID subline; optional note subline when `notes` is set), **Status**, **Shipment** (method plus asynchronously resolved tracking numbers), **Created** / **Ordered** / **Estimated arrival** / **Received** / **On shelves**, **Vendor**, **Items**, **Product total**, **Shipping total**, **Surcharge total**, **Total**, **Actions** (**Delete** per row).

- **Delete** opens a destructive **`ConfirmDialog`**; on confirm calls **`DELETE /api/v1/purchase-orders/{uuid}`** (same guards as PO detail — blocked when received inventory or FIFO lots exist). Reloads the history table on success.

| Column       | API / logic                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Shipment** | `shipment_method` on **`purchase_orders`** (`air` \| `sea` \| null). Set on import/create form, PO header edit, or inferred once from line products when unset (unambiguous air-only or sea-only).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| **Tracking** | `shipment_tracking_numbers` JSON array on **`purchase_orders`** (up to 40 values, 255 characters each). After the list or detail header renders, visible numbers post to **`POST /api/v1/shipment-tracking/resolutions`**. The worker tries **17TRACK** first, then Kuaidi100, AfterShip, Ship24, and ParcelsApp. Queued/resolving numbers remain plain text with a spinner; the UI polls the same bulk endpoint and creates one external link only after the credential-free browser worker finds real shipment events. Successful provider URLs are cached globally in `shipment_tracking_resolutions`; no-match/failed checks remain plain text and use cooldowns before retry. |

### Pagination

Loads **`GET /api/v1/purchase-orders`** with query params aligning to filters/sorts (see network layer in script).

### “Create / Import” card

Single multipart submission **`POST /api/v1/purchase-orders/import`** containing:

| FormData field                                                                                                                                                | Source                                                                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **`file`**                                                                                                                                                    | Required CSV or XLSX picker (`.csv`, `.xlsx`)                                                                                                                           |
| **`vendor`**                                                                                                                                                  | Datalist-backed text (seeded vendors merged from filter API + defaults)                                                                                                 |
| **`supplier_order_id`**, date fields (**`ordered_date`**, **`shipped_date`**, **`estimated_arrival_date`**, **`received_date`**, **`fully_on_shelves_date`**) | Optional structured metadata                                                                                                                                            |
| **`product_total`**, **`shipping_total`**, **`surcharge_total`**                                                                                              | Decimal text inputs (**CAD** labeling for product total in UI copy)                                                                                                     |
| **`product_total_includes_fees`**                                                                                                                             | Checkbox (**Dspiae**, **Stedi**, **Other/multi**): treat product total as **total paid CAD**; server splits product vs shipping using invoice HKD product/freight ratio |
| **`notes`**                                                                                                                                                   | Optional free text                                                                                                                                                      |
| **`shipment_method`**                                                                                                                                         | Optional **`air`** or **`sea`** (stored on PO header)                                                                                                                   |

Selecting a CSV may **auto-sniff vendor** (`sniffVendorFromCsv`) before upload to pre-fill **Vendor** (DSPIAE / Stedi heuristics in first 4KB).

### Dspiae / Stedi / Other/multi — PM broker invoice preview

When **Vendor** is **Dspiae**, **Stedi**, or **Other/multi**, the primary button reads **Preview import**. Clicking it calls **`POST /api/v1/purchase-orders/import/preview`** (same FormData fields as import, minus PO UUID linkage) and opens **`PoImportPreviewDialog`** (`resources/js/components/purchaseOrders/PoImportPreviewDialog.vue`).

Use **Other/multi** for mixed PM broker invoices (e.g. SNAA kits, cutting mats) that are not Dspiae/Stedi catalog.

Preview table columns: **Item · SKU · Qty · Unit (HKD) · Line (HKD) · Unit (CAD) · Line (CAD)** plus footer totals and summary (**product total CAD**, **shipping CAD**, **vendor total HKD**, implied **FX** when product total CAD is supplied).

**Confirm import** reuses **`POST /api/v1/purchase-orders/import`** with the same file/metadata (no second upload picker).

PM broker layout (`.xlsx` or `.csv`): preamble rows skipped; header **`Customer | Item | SKU | Qty | unit price | Amount`**. Parser tries PM layout first for Dspiae/Stedi/Other/multi vendors, then falls back to native vendor CSV formats.

**PM invoice SKU rules:** each spreadsheet row becomes **one ERP product / one PO line** — import does **not** merge PM invoice rows. When the **SKU** column holds a size/scale token (`A3`, `1/144`, …) rather than a unique vendor code, import derives distinct ERP SKUs from **Item** name and/or numeric **Customer** line refs (`PM-34`, slug from item text). Stedi/Dspiae rows with real codes (`MS-001`) stay one row per line; accidental duplicate SKUs within the same file get a `-L{row}` suffix.

Other vendors keep the direct import path (no preview dialog).

Success renders link to **`/purchase-orders/{purchase_order_uuid}`** plus **items**, **lots**, **`shipping_per_unit`** summary.

### Combined CAD payments across POs

History rows include selection checkboxes. Selecting at least two POs exposes **Record combined
payment**, which opens `PoCombinedPaymentDialog`.

| Step                | API / behavior                                                                                                                                                                    |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Preview             | `POST /api/v1/purchase-orders/combined-payments/preview` with `purchase_order_ids[]`, `total_paid_cad`, and `includes_shipping`                                                   |
| Record              | `POST /api/v1/purchase-orders/combined-payments` with the same body and optional exact `allocations[]`; returns HTTP `201` and persists the payment plus per-PO snapshots         |
| Amount entry        | **Total paid only** accepts one CAD value; **Product + shipping split** accepts both combined CAD amounts, calculates the total, and allocates each pool separately               |
| Products only       | Combined CAD is allocated by each PO's `vendor_product_total`; each existing `shipping_total` remains unchanged                                                                   |
| Products + shipping | Requires each PO's `vendor_shipping_total`; combined CAD is allocated across vendor product and freight totals using one implied vendor→CAD FX rate                               |
| Exact CAD split     | After preview, **Enter exact CAD amounts manually** makes each row's Product (CAD) and Shipping (CAD) editable; recording is disabled until all rows add up exactly to Total paid |

The preview shows each PO's shipment method, vendor product/freight totals, allocated CAD product
and shipping, and resulting shipment total. Penny rounding is assigned deterministically so all
allocations reconcile exactly to **Total paid (CAD)**. Surcharges are not part of the combined
payment and remain on their original PO. For an exact manual split, each PO's product CAD amount
sets that PO's product FX and line-item CAD costs while the payment header retains the overall
weighted FX for reconciliation.

Selected POs must use the same non-CAD currency. The operation is blocked when a PO already belongs
to a combined payment or has received quantities/FIFO lots, because changing FX after receipt would
invalidate historical inventory costs. Deleting a PO linked to a combined payment is also blocked
with HTTP `409`, preserving the payment's reconciliation record.

PM invoice imports persist the parsed freight amount as `purchase_orders.vendor_shipping_total`;
the original item values remain in `purchase_order_items.vendor_unit_cost`. PO detail displays both
the original vendor-currency freight and the allocated/current CAD shipping total.

**Product vendor on import:** the **Vendor** field on the import form is applied to every matched/created **`products.vendor`** row and each new or merged **`purchase_order_items.vendor`** line on that import. Existing products with a different vendor are updated to the PO import vendor for SKUs in the file (manual vendor edits elsewhere are unchanged until the SKU appears on a future import).

**Import blocked** state enumerates **`importIssues`** as bullet list requiring operator correction before retry.

---

## Detail page (`/purchase-orders/:id`)

### Header / metadata editor

Loads **`GET /api/v1/purchase-orders/{uuid}`** (`PurchaseOrderResource` projection). The read-only header summary shows the line-item count and **Total quantity**, calculated as the sum of every line's **`qty_ordered`** (null quantities count as zero), plus every saved shipment tracking number using the same async resolution flow as the history grid (spinner while queued/resolving; one verified provider link when cached or newly resolved). **Edit** accepts up to 40 **Shipment tracking numbers**, entered one per line (commas are also accepted), through **`PATCH /api/v1/purchase-orders/{uuid}`**.

**View products in grid** opens `/products?purchase_order_uuid={uuid}` in a new browser tab, with the Products grid PO filter preselected for the current purchase order.

**Try beta UI** opens `/purchase-orders/:id/beta`. The classic page is unchanged otherwise and remains the default from the Purchase Orders list.

Supports inline edit + save for PO-level fields (**vendor_currency_code**, **`fx`** fields, **`supplier_order_id`**, dates, monetary totals **`product_total`, `shipping_total`, `surcharge_total`, `vendor_product_total`**, **`notes`**, **`is_done`**, **`exclude_from_latest_arrivals_ordering`**).

**`exclude_from_latest_arrivals_ordering`** (checkbox on edit): when **true**, the PO keeps **`received_date`** for inventory/lots but is **skipped** when building storefront Latest Arrivals collection order (`LatestArrivalCatalogOrderService`). Use for restock invoices that should not bump products ahead on the homepage. Shared SKUs fall back to their next eligible received PO. Re-run **Push to Shopify — Latest Arrivals order** on a received PO after toggling to refresh Shopify collection sort.

After a successful push finalize, **`ShopifyLatestArrivalsCollectionReorderService`** reorders the **`latest-arrivals`** smart collection and **waits for Shopify’s async reorder job** (up to **`SHOPIFY_LATEST_ARRIVALS_REORDER_JOB_WAIT_SECONDS`**, default 120s) before marking the push complete. Manual refresh without re-pushing all products: **`php artisan shopify:latest-arrivals-collection-reorder`**.

### Status presentation

Shows computed **lifecycle status**: **draft**, **ordered**, **shipped**, **received**, **on_shelves** with human labels (`draftStatusLabel`).

### Re-import / import more PO document

Controls (same card on detail page):

| Field / control                                | Notes                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Product total (CAD)** / **Total paid (CAD)** | Optional; used for FX on new lines when vendor currency is not CAD                                                                                                                                                                                                                                                                                                                                                               |
| **`product_total_includes_fees`**              | Checkbox when PO vendor is **Dspiae**, **Stedi**, or **Other/multi**: treat entered CAD as total paid; server splits product vs shipping using invoice HKD ratio                                                                                                                                                                                                                                                                 |
| **Shipping total**                             | Optional; disabled when includes-fees is checked                                                                                                                                                                                                                                                                                                                                                                                 |
| **CSV / XLSX file**                            | `.csv`, `.xlsx`                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **Clear PO receipt data first**                | Replace (**Re-import**) only: purge PO-linked lots/movements and clear **`qty_received`** before replacing lines                                                                                                                                                                                                                                                                                                                 |
| **Re-import**                                  | **`import_mode=replace`** + **`purchase_order_uuid`** on **`POST /api/v1/purchase-orders/import`**                                                                                                                                                                                                                                                                                                                               |
| **Import more**                                | **`import_mode=append`** — merges rows into an existing line when the same **`product_id`** is already on the PO; otherwise adds lines. **Within-file duplicate SKUs** are merged before insert (qty summed; **`unit_cost`** qty-weighted average). Header **`product_total`**, **`shipping_total`**, **`vendor_product_total`**, and **`surcharge_total`** combine with the new import (after all-fees CAD split when checked). |

**Dspiae / Stedi / Other/multi** on detail page: **Preview re-import** / **Preview import more** call **`POST /api/v1/purchase-orders/import/preview`** (includes **`purchase_order_uuid`** + **`import_mode`**) and open **`PoImportPreviewDialog`**. **Confirm** runs the same import POST with the already-selected file.

Typical two-invoice workflow: import invoice 1 from list page → open PO → **Import more** with invoice 2 (preview + includes-fees if needed). Header totals accumulate automatically; **`product_total` (CAD)** is also recomputed from all line unit costs after import.

**Duplicate SKU lines (maintenance):** New imports merge duplicate SKUs automatically. To fix historical POs, run **`php artisan purchase-orders:dedupe-lines`** (dry-run) or **`php artisan purchase-orders:dedupe-lines --execute --yes`** (optional **`--po={uuid}`**). Merges by **`product_id`**, sums qty ordered/shipped, applies inventory-aware **`qty_received`** merge, qty-weighted **`unit_cost`**, reassigns PO-linked lots, then recomputes PO derived totals.

Other vendors: direct re-import / import more (no preview dialog).

**Plamod order-details CSV:** **Qty Filled** maps to **`qty_shipped`** only. **`qty_received`** stays **null** on import (no PO-linked inventory lots). Receipt is recorded later via inventory check apply, manual line edits, or explicit **Qty received** columns in generic import CSVs.

### Line grid (per SKU)

Columns include SKU, **product vendor** (catalog `products.vendor`), qty **ordered/shipped/received/damaged**, **unit_cost** editable, linkages to **`product_id` UUID**, optional **available / maintain / not_arrived / reorder** aggregates, **`latest_landed_unit_cost`**, **`selling_price`**, multiplier display, etc. **Damaged** (`purchase_order_items.qty_damaged`, default `0`) is editable per line, cannot exceed Qty received, and remains separate from the gross received quantity for receiving history.

**Product vendor (Other/multi and mixed broker POs):** **Other/multi** imports create new catalog rows with **`product.vendor` null** (PO header stays **Other/multi**). An amber alert shows **`counts.unassigned_product_vendor`** when any line’s catalog product has no vendor. Assign per row in the **Product vendor** column (**`PATCH /api/v1/products/{uuid}/vendor`**) or bulk-update selected lines (**`changes.product_vendor`** on **`PATCH /api/v1/purchase-orders/{uuid}/items`**). Vendor picker uses **`GET /api/v1/products/filter-options`**; a new name is added to suggestions only after you save (blur/Enter), not on each keystroke. **Dspiae** / **Stedi** single-vendor imports still set vendor on **new** products only; re-import never overwrites an existing **`product.vendor`**.

**Items table sorting:** Click any data column header (except checkbox and **PO Lines**) to sort visible rows client-side. First click sorts ascending; clicking the active column toggles descending. Sort applies after the **Search** filter. Default sort: **SKU ascending**.

**Not arrived / reorder on line grid:** Matches the PLAMOD restock proposal and Products reorder formula — sums **qty ordered** on POs that are **not fully on shelves** (`fully_on_shelves_date` null) and are **not draft** (must have **ordered** or **shipped** date). Received-but-not-shelved lines remain included. Draft PO lines (including the PO you are viewing) are excluded from **Not arrived**. **Reorder** = `max(0, Maintain − Available − Not arrived)`.

Interactions:

| User action                                               | API                                                                                                                                                                                                                                                                                                                                                                                 |
| --------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Edit qty ordered / shipped / received / damaged           | PATCH item endpoints (**`/api/v1/purchase-order-items/{id}`** or bulk patch path). **`qty_received` may exceed `qty_ordered` / `qty_shipped`** (over-receipt). **`qty_shipped` still cannot exceed `qty_ordered`**. **`qty_damaged` must be 0–received**. Receipt quantities cannot be changed after PO-linked inventory lots exist. Rows highlight yellow when received ≠ ordered. |
| Set **product vendor** (inline or bulk)                   | **`PATCH /api/v1/products/{uuid}/vendor`**; bulk **`changes.product_vendor`** on **`PATCH /api/v1/purchase-orders/{uuid}/items`**                                                                                                                                                                                                                                                   |
| Edit barcode column (when surfaced)                       | product-level barcode patch proxied via item row workflows                                                                                                                                                                                                                                                                                                                          |
| Edit unit cost                                            | item PATCH validations                                                                                                                                                                                                                                                                                                                                                              |
| **Bulk update selected rows** (`BulkUpdatePoItemsDialog`) | **`PATCH /api/v1/purchase-orders/{uuid}/items`** with selected IDs payload                                                                                                                                                                                                                                                                                                          |
| **Turn into water decals** (`PoWaterDecalPromoteDialog`)  | **`POST /api/v1/purchase-orders/{uuid}/water-decals/preview`** then **`POST .../water-decals/apply`** — preview shows intention per row (merge / promote / blocked); operator can override SKU, title, vendor, grade; merge requires checkbox confirm; all promoted SKUs get **`WD-`** prefix; vendor defaults to **`Water Decals`**                                                |

### Apply received quantities → available inventory

- Button triggers **`POST /api/v1/purchase-orders/{uuid}/apply-received-to-available`**.
- Backend calculates each line's sellable receipt as **`max(0, qty_received - qty_damaged)`**, sums it per **`product_id`**, and increments **`products.available_qty`** transactionally. Gross received and damaged quantities remain unchanged for receiving history; UI reports both sellable units added and damaged units excluded.
- **Requires every PO line** to have **`qty_received > 0`**; otherwise returns **422** with per-SKU issues (same rule as Prepare).
- **Skip receiving-kit scanning** is an explicit exception in the receiving card. After confirmation, it bulk-overwrites every line's **`qty_received`** with its positive **`qty_shipped`** value through **`PATCH /api/v1/purchase-orders/{uuid}/items`** (`changes.set_received_to_shipped=true`). Lines with missing/zero shipped quantities block the action. Operators then enter damaged quantities before Prepare / Apply.

### Apply inventory-check snapshot → qty received on PO

- User picks an **`inventory-check` session UUID** (`GET`-listed with pagination helpers in page script).
- Confirmed apply posts **`POST /api/v1/purchase-orders/{uuid}/apply-inventory-check`** with chosen check id.
- **Behavior:** resets PO receipt artifacts per service (removes movements/lots, clears **`qty_received`**, resets **`qty_damaged`** to `0`) then overlays quantities aggregated by **trimmed SKU** from check rows; **`ProductLatestCostCacheService`** recomputes impacted SKUs; UI renders **warnings** (e.g., SKUs counted in CSV but absent on PO) inside dedicated panel.

### Draft workflow utilities

Available when PO is drafting / mixed states per UI guards:

| Feature                              | Endpoint                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Export order CSV                     | **`GET /api/v1/purchase-orders/{uuid}/draft-lines-export`** — **SKU**, **Product Name**, **Qty**, **CAD** unit/line first; **Dspiae / Stedi / Other/multi** add **HKD** columns after CAD. No barcode/shipping; **qty 0** omitted. FX: current PO or latest PO with **HKD** (broker vendors always use HKD for FX lookup even when PO header says CAD). HKD from `vendor_unit_cost` or CAD÷FX. |
| **Merged vendor order CSV** (manual) | See **`docs/process/vendor-order-csv-export.md`** — combine multiple draft POs (e.g. Dspiae + Stedi) with **shipping + landed** columns for PM broker placement. One-off: `docker exec pricing-tool-php php tmp-export-merged-po.php`. UI export button planned.                                                                                                                               |
| Paste SKUs textarea → append lines   | **`POST /api/v1/purchase-orders/{uuid}/draft-products`** validating multi-line SKU input                                                                                                                                                                                                                                                                                                       |
| Created from Products grid           | **`POST /api/v1/purchase-orders/drafts/create-from-products`** — sets line **`unit_cost`** from **`latest_unit_cost`** and fills header **`product_total`** / **`shipping_total`** (CAD estimates from cached costs × reorder qty)                                                                                                                                                             |

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

| Step key                            | Button                    | Endpoint                                                                                                                                                                                                                                                                                               |
| ----------------------------------- | ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `crawl_desc_image_price`            | Crawl new / **Skip**      | `POST .../workflow-actions/crawl-new-products`. **Skip** checks the step (Plamod: confirm). **Crawl new** on non-Plamod: confirm. Re-verify auto-checks crawl only for **Plamod** when PDP exists and step was not skipped.                                                                            |
| `select_and_arrange_product_images` | Review images / **Defer** | **Review images** opens **`/products?purchase_order_uuid=…`** in a **new browser tab** (manual image pick/order in Plamod drawer). **Defer** (confirm) checks the step and sets **`select_and_arrange_product_images_deferred`** so the PO workflow can continue without curating every product first. |
| `set_selling_price`                 | Set/review                | `GET .../workflow-actions/set-prices/preview` then confirm → `POST .../workflow-actions/set-prices` with optional `overrides[]` (`product_uuid`, `price`) for manual dialog edits. Operator checks the step manually after approving the preview (not auto-verified).                                  |

**Set/review preview dialog** (`PoWorkflowSetPricesDialog`): **Set/review** loads preview first. **Landed** uses the product line from the latest PO whose **`shipping_total` is not `NULL`** (`ProductLatestArrivedLandedUnitCostResolver`, ordered by `ordered_date`). An explicit shipping total of **`0.00` is valid**. CAD unit cost comes from that PO, while shipping and surcharge are allocated using quantities across **all lines on that PO**. This allows new products on the current unreceived PO to be priced as soon as shipping is entered. Lines with no PO where shipping has been entered land in **Missing landed cost**. **Formula:** landed × **1.5**, rounded **up** to the next **X.99** at or above that target, then one **X.99** tier lower only when that formula price is **> 1.55×** landed and the lower tier remains **≥ 1.45×** landed. Sections (in order): **New prices** (shows current selling price when the preview row has one), **Price updates** (raises only — formula higher than current), **No price change** (already matches formula, or current price is higher than formula; **Override** column **?** help explains that Apply skips these rows, **Current** is catalog price, and **Override** pre-fills the formula price), **Missing landed cost** (skipped unless an operator enters an override). Within each section, **new-on-PO products appear before existing** SKUs until a column header is clicked; each section table supports **client-side sort** on **SKU**, **Product**, **PO**, **Landed**, **Current**, **Mult.**, and **Proposed/Override** (asc/desc toggle per section, ▲/▼ indicator on the active column). Table headings remain visible while scrolling. Displayed multipliers below **1.45×** or above **1.60×** use bold red alert badges for review. Proposed/override price inputs accept manual CAD values (`0.00` format), highlight edited rows, recalculate the target multiplier in the **Mult.** column (`current → override`, even when both values round to the same multiplier), and **Reset** returns a row to the formula/current value. **Apply prices** writes formula rows plus any valid overrides; **Cancel** closes without changes.

Every **Price updates** and **No price change** row with a suggested price shows **Use suggested**, including rows where Current already equals the suggestion. Clicking it explicitly marks the displayed formula price as a manual override. The row changes to **Reset**, the global **Apply prices** button becomes enabled, and applying persists that exact suggested amount.

**Selling price history** (`GET .../selling-price-history`): append-only **`product_selling_price_history`** rows written whenever a selling price actually changes. PO **Set/review → Apply prices** logs **`source: po_workflow`** with **`purchase_order_id`** set so the PO detail page can list every change made from that PO (SKU, product, previous → new, timestamp). Manual edits via **`PUT /products/{uuid}/selling-price`** (Price Research, Products) log **`source: manual`** with no PO link. Re-applying the same price does not create a duplicate history row. Product-scoped history: **`GET /products/{uuid}/selling-price-history`** (includes optional **`purchase_order_uuid`** when the change came from a PO workflow).

| `export_to_shopify_get_handles` | Export | `GET .../workflow-actions/export-shopify-content/preview` then **Push to Shopify** → `POST .../workflow-actions/export-shopify-content/push` (returns **202** + **`batch_id`**) |

**Export to Shopify preview dialog** (`PoWorkflowExportShopifyDialog`): **Export** loads preview for **products on this PO without a stored handle** (includes re-orders from earlier POs). Fixed export type **Shopify content (images + description)** — same field set as bulk **`shopify_content`** CSV (includes **`available_qty`** on create). **Push to Shopify** queues one **`PushSelectedProductToShopifyJob`** per eligible product on the **`shopify`** queue (avoids HTTP **60s** client timeout on large POs). SPA polls **`GET .../export-shopify-content/status?batch_id=`** until **`phase`** is **`complete`** or **`failed`**; shows progress bar while **`pushing`** / **`finalizing`**. Each job calls Shopify Admin **`productSet`** (requires **`write_products`** + **`write_inventory`** OAuth scopes and a resolvable inventory location). When **`published_on_shopify`** is true, also publishes to all sales channels via **`publishablePublish`** (**`read_publications`** + **`write_publications`**). Table columns: SKU, Product, Handle (blank), Status. Handles returned from Shopify are saved on **`products.handle`**. Image tunnel recommended for signed image URLs; without tunnel, products push without images. **Re-verify** auto-checks **`export_to_shopify_get_handles`** when **every product on the PO** has a non-empty **`products.handle`** (also runs after a successful push).
| `import_handle_only` | Pull handles | `GET .../workflow-actions/pull-handles/preview` then **Pull handles** → `POST .../workflow-actions/pull-handles` |

**Pull handles preview dialog** (`PoWorkflowPullHandlesDialog`): **Pull handles** loads preview for **products on this PO without a stored local handle** (includes re-orders). If **`pull_count`** is zero, shows a clear message that all products on the PO already have handles. Otherwise lists SKU, Product, local handle (blank), and **mirror handle** from the current Shopify mirror (best-effort before sync). **Pull handles** confirms a read-only Shopify product sync, then copies handles by SKU into **`products.handle`**. SKUs not found in Shopify are listed after the run.
| `update_product_available_with_shopify_current_inventory_quantity` | Prepare + Apply received | `POST .../workflow-actions/prepare-inventory` validates **qty received** on all lines. When lines have **Qty shipped** but blank **Qty received** (typical after **Plamod CSV import**), SPA prompts to copy shipped → received via bulk patch, then retries Prepare. If **`products`** and **`inventory_levels`** mirror syncs both completed within **1 hour** (`SHOPIFY_PO_PREPARE_MIRROR_FRESHNESS_SECONDS`, default 3600), **skips** Shopify pull unless the user confirms a stale-mirror refresh (confirm sends **`pull_shopify: true`**, which always runs a PO-SKU pull even when the full-store mirror is still fresh). Confirm → refreshes **`shopify_inventory_levels`** for PO SKUs only (not full store, not Maintenance timestamps). Prepare reads **inventory level rows** after pull (not stale **`shopify_product_variants.inventory_quantity`** cache). Decline → proceeds with existing mirror data. SKUs missing from mirror → 422 with hint to run Maintenance / `shopify:sync products`. Then `POST .../apply-received-to-available`. SPA uses no client timeout on Prepare. |
| `mark_published_on_shopify` | **Mark published** | `POST .../workflow-actions/mark-published-on-shopify` — sets **`published_on_shopify`** true for **all** PO products (ERP only until push). |
| `mark_latest_arrival` | **Clear old latest** + **Mark latest** | **Clear old latest:** `POST .../workflow-actions/clear-stale-latest-arrival` (same as before). **Mark latest:** `POST .../workflow-actions/mark-latest-arrival` — sets **`latest_arrival`** true for PO products **except** `main_type` **tools** (response includes **`skipped_tools`**). Legacy combined endpoint **`mark-latest-arrival-published`** still exists. |
| `import_product_available_quantity` | **Push to Shopify** | Preview + **`POST .../workflow-actions/push-inventory`** (returns **202** + **`batch_id`**) — **requires PO `received_date`** (SPA shows amber warning and disables **Push to Shopify** until set; API returns **422** if missing). Queues one **`PushSelectedProductToShopifyJob`** per eligible PO product on the **`shopify`** queue (same full push options as bulk Products → Push to Shopify). SPA polls **`GET .../push-inventory/status?batch_id=`** until **`phase`** is **`complete`** or **`failed`**; shows progress bar while **`pushing`** / **`finalizing`**. Full ERP → Shopify sync per product via **`productSet`**: title, description, images (when tunnel on), tags (incl. **`latest arrival`** when ERP flag set), price, publish status, and **sellable** inventory (`shopify_push_qty = max(0, erp_available - erp_hold)`). **Preview** (`PoWorkflowPushInventoryDialog`) table columns include **Available**, **Hold**, **Push qty**, and current **Shopify qty**; **Copy product names** copies preview titles one per line (Latest Arrivals sort order). When **`published_on_shopify`** is true, **`publishablePublish`** to all publications. **Preview** sorts products on **this PO only** (CCS toys → Sazabi bust → PG → Mega → MG (MGEX first) → RE → Full Mechanics → RG → HGUC → HG → SD/BB/EX-Standard → Kun DX → Macross → 30MM (Armored Core first) → 30MF → 30MS → 30MP → Figure-rise → Entry Grade → Pokemon → Keroro → Option parts → Action base → System base → LED → Option parts set Gunpla, newest within each grade). After batch jobs finish (no failures), a finalize step reorders the **Latest Arrivals** Shopify collection when **`SHOPIFY_LATEST_ARRIVALS_COLLECTION_GID`** is set: **received POs only** by **`received_date` desc** (unreceived POs ignored; products on multiple received POs count under their **newest received** PO only), then the **same grade order within each PO** (`LatestArrivalCatalogOrderService` + **`collectionReorderProducts`**; collection must be **manual** sort). Config: **`config/latest_arrival.php`**. Barcode scan override import remains under the export card (**Import product quantity**). Avoids HTTP **524** tunnel timeouts on large POs. |

For the Prepare + Apply received step, Prepare also rejects **`qty_damaged > qty_received`**. Apply adds only **received − damaged** sellable units to ERP available inventory.

### Auxiliary modals reused from Products domain

- **`ImportHandlesCard`** — same handle importer as Products tab (**`POST /api/v1/products/import-handles`**) surfaced for PO-aligned workflows.
- **`ImportInventoryQuantityOverrideCard`** — quantity override importer.
- **Bulk Shopify export / PDP recrawl dialogs** behave like **`ProductsPage`** equivalents but seed selected IDs from **`poProductUuids` computed unique list** derived from PO line **`product_id` UUID references.
- PDP batch queue success navigations push **`sync-progress`** with **`batch_id`**, mirroring **`ProductsPage`**.

## Beta workspace (`/purchase-orders/:id/beta`)

Opt-in redesign. Classic `/purchase-orders/:id` stays the default and the write path for import, receiving, Shopify, bulk, and delete.

| Area     | Behavior                                                                                                                                                                                                                                                                                                                                                                                  |
| -------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Chrome   | Hides `AppNav`. Storefront `colorBar.png` is 8px high, 65% width, right-aligned, no hairline under the bar. Rail uses icons plus a color-bar accent. **Edit details** returns to the original page.                                                                                                                                                                                       |
| Overview | Mission-control matching the v7 design: 3-step timeline, receiving progress bar, compact attention headlines, recent activity, large order-health count, costs with **Show calculation**. Uses **`GET /api/v1/purchase-orders/{uuid}`** only (does **not** auto-run `workflow-verify`). KPI money uses thousands separators (`CAD 6,842.30`). Meta shows supplier order id, not the UUID. |
| Actions  | Catalog of PO tools. Implemented now: **Set or review prices** (`GET/POST .../workflow-actions/set-prices`) and **Selling price history** (`GET .../selling-price-history`). All other actions open classic with `?from=beta`.                                                                                                                                                            |
| Tabs     | Overview, Workflow, Order lines (read-only), Import & receiving (summary + classic link), Activity.                                                                                                                                                                                                                                                                                       |
| E2E      | `tests/e2e/purchase-order-detail-beta.spec.ts` creates a disposable test PO, exercises chrome/tabs/Actions/Continue/classic handoff, then deletes the PO.                                                                                                                                                                                                                                 |

---

### Delete PO

- Guarded **`DELETE /api/v1/purchase-orders/{uuid}`** — confirm destructive dialog in UI (`PurchaseOrderDeleteService` validations apply server-side).

### PO lines shortcut drawer

Opens **`ProductPoLinesDrawer`** for selected product UUID contextual navigation without leaving PO screen.
