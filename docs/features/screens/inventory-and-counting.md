# Inventory check & counting

This area spans **three user experiences**:

1. **Admin — CSV-backed inventory checks** (`/inventory-check`, `/inventory-check/:id`)
2. **Admin — unified apply API** reused by employee sessions ( **`POST .../inventory-check/{uuid}/apply`** )
3. **Employee — barcode scan counting** (`/employee/inventory-count`)

---

## 1) Inventory Check list (`/inventory-check`)

**Page:** `InventoryCheckPage.vue`

### Create / Import

- File input (CSV).
- Optional **Session note** text field (stored on **`inventory_check.notes`**, max 2000 chars).
- **`Import CSV`** posts **`multipart`** to **`POST /api/v1/products/import-inventory-check`** (fields: **`file`**, optional **`notes`**).
- Outcome banner shows **`inventory_check`** uuid, **`rows_parsed`**, **`matched`**, **`applied`**, **`unmatched`**, **`ambiguous`**, plus **`not_applied`** metrics and downloadable row buckets with reasons.
- Direct link hops to **`/inventory-check/{uuid}`** detail.

### History table

Loads **`GET /api/v1/inventory-check`** (paginates `per_page=50` in UI defaults).

Shows counts per session (items/matched/unmatched/etc.) plus metadata timestamps and an editable **Note** column (**`PATCH /api/v1/inventory-check/{uuid}`** `{ notes }`).

### Delete session

**`ConfirmDialog`** warns destructively → **`DELETE /api/v1/inventory-check/{uuid}`** (`200`). Aligns with **confirm-delete** practice.

📄 Canonical CSV semantics: **`docs/requirements/inventory-check.md`**.

---

## 2) Inventory Check detail (`/inventory-check/:id`)

**Page:** `InventoryCheckDetailPage.vue`

### Load & download originals

- Fetch **`GET /api/v1/inventory-check/{uuid}`** expanding **all line items**.
- **Session note** field at top of page; save with **`PATCH /api/v1/inventory-check/{uuid}`** `{ notes }`.
- **`Download CSV`** issues browser navigation **`GET /api/v1/inventory-check/{uuid}/download`**.

### Line review grid

Shows columns for handle/vendor/sku/type/names/qty snapshots/differences/notes + status chips (**matched / unmatched / ambiguous**) + **`applied`** flags.

Interactions:

| Action                                     | Endpoint                                                                                                                                                                                                                                                                     |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Edit qty + product name drafts per row     | **`PATCH /api/v1/inventory-check/{uuid}/items/{lineId}`** `{ quantity, product_name }`                                                                                                                                                                                       |
| Resolve red “No active product found” rows | In-page **`InventoryCheckResolveProductDialog`** (**Resolve**, red) searches **`GET /api/v1/products`**, assigns with **`POST /api/v1/inventory-check/{uuid}/items/{lineId}/assign-product`** `{ product_id }`, or creates through **`POST /api/v1/products`** before assigning the new product |
| Reassign matched / ambiguous rows | Same dialog opened via **Reassign** (neutral) on any row while session **`workflow_state`** is not **`applied`**; backend assign endpoint replaces the linked product and refreshes row snapshots |
| **Apply session** modal confirm            | **`POST /api/v1/inventory-check/{uuid}/apply`** `{ apply_quantity, apply_name, apply_quantity_mode ('overwrite'\|'increment') }` delegated to **`EmployeeInventoryCountService::applySessionQuantities`** (shared service path for consolidated apply behavior)              |

**Apply** confirms once via **`window.confirm`** before POST.

Filters / search purely client-side: **applied / unmatched / ambiguous** subsets + textual search haystack assembled from row fields. The resolve dialog stays on `/inventory-check/:id`; successful assign/create refreshes the session data without clearing the page-level search/filter state.

### Export matched product IDs

Opens **`BulkExportDialog`** constrained to **`exportProductIds`** union of **`product_id` UUID references** extracted from matched lines—supports same export types described in **`screens/products.md`** (Shopify CSV, Shopify content prepare, rename→sync-progress auto export).

---

## 3) Employee inventory count (`/employee/inventory-count`)

**Page:** `EmployeeInventoryCountPage.vue` (**only nav link for employee role**)  
**State:** persists **`localStorage`** key **`employee_inventory_count_session_id`**.

### Session bootstrap

On mount **`ensureSession()`**:

1. If remembered UUID loads **`GET /api/v1/inventory-check/employee/sessions/{uuid}`** successfully → hydrate.
2. Else **`POST /api/v1/inventory-check/employee/sessions`** **`{ name: null }`** with role attribute defaulting from server meta → obtains new session payload (`201`).
3. Session metadata includes **`source: employee_scan`**, **`workflow_state`**, **`created_by_role`**, aggregates (**lines/units/issues**).

### Scan loop

Barcode textbox (auto-refocus post-scan) submits **`POST .../sessions/{uuid}/scan`** `{ barcode }` → returns refreshed session (**items**, match errors, thumbnails via **`GET product-assets/.../view`** behind the scenes).

**Visual affordances:**

- If latest scan resolves to **`unmatched`** with message containing **`No active product found`** → **full viewport + nav red styling** warns operator (`employeeInventoryScanNotFoundBg`).

### Quantity / label edits inline

Uses **`PATCH .../sessions/{uuid}/lines/{lineId}`** with optional **`quantity`** / **`product_name`** (422 if neither provided).

### Remove line / flag issue

- **`DELETE .../lines/{lineId}`**.
- **`POST .../flag-issue`** **`{ barcode, reason? }`**.

### New session confirmation

Ends current ephemeral session (**`window.confirm`**) → creates fresh session (**state memory cleared** afterward).

---

## Closing notes — apply semantics divergence

Imported historical CSV (“admin inventory check”) may auto-apply some fields during **`import-inventory-check`** whereas review screen offers **explicit apply** bridging into session apply service—when changing code, reconcile:

- **`docs/requirements/inventory-check.md`** (CSV import baseline)
- Service implementations under **`EmployeeInventoryCountService`** (merged apply pathways)
- API tests **`InventoryCheck*`** + employee session specs
