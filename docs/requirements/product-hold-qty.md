# Product hold qty (event reserve) — requirements draft

**Status:** Implemented (v1).

## Goal

Reserve units on the side for big events / exclusives while keeping one ERP view of total on-hand stock. Shopify (and similar storefront pushes) only sell what is not held.

## Mental model (agreed)

| Field | Meaning |
| --- | --- |
| **`available`** | Total qty we have (Shopify-sellable + held). Unchanged semantics for receiving, inventory checks, PO apply, etc. |
| **`hold`** | Qty withheld from online sale (`products.hold_qty`, editable in grid). |
| **`maintain`** | Unchanged — reorder target only; operator handles mentally alongside `available`. |

**Shopify push qty:** `max(0, available - hold)`

**Shopify pull → ERP:** `available = shopify_qty + hold`  
(So pull restores total on-hand; held portion stays in ERP even when Shopify shows less.)

## Validation

- `hold` is a non-negative integer (nullable / 0 = none).
- **`hold <= available`** — reject or clamp with clear UI error (prefer reject on save).

## UI (when built)

- Products grid: editable **Hold** column (inline, same pattern as Available / Maintain).
- Push inventory preview: show **Available**, **Hold**, **Push qty** (`available - hold`) per row.
- Optional later: filter “has hold > 0”, bulk clear hold after event — not required for v1 unless requested.

## Shopify integration scope (v1)

Apply hold subtraction only on paths that **set Shopify inventory from ERP**, e.g.:

- `ShopifyProductUpsertFromErpService` / create-from-ERP inventory quantity
- PO workflow **Push inventory** preview + push

Do **not** change product create/update payloads beyond inventory quantity math.

## Out of scope (v1)

- Per-event allocation table, dates, or event names.
- Second Shopify location for reserve stock.
- Changing **`maintain`** or reorder formulas because of hold.

---

## Deferred — revisit before/after v1 ship

**Other exports and CSVs still use raw `available_qty` today.** When hold ships, decide case-by-case whether each export should use sellable qty instead:

| Export / flow | Current column | Revisit? |
| --- | --- | --- |
| Barcoded inventory CSV (`/products/export/barcoded`) | `available amount` = `available_qty` | TBD — may want sellable or both columns |
| Filtered products export (`/products/export`) | includes available | TBD |
| Shopify content / rename export | N/A inventory | No change expected |
| Employee inventory count / check apply | ERP `available` | Keep total on-hand (`available`), not sellable |
| Maintenance / operational dumps | varies | TBD |

**Reminder:** After hold is implemented, walk this table and update exports only where operators need sellable vs total distinction. Until then, document in feature catalog that exports ignore hold.

---

## Related code (for implementers)

- Push uses `product->available_qty` today: `ShopifyProductUpsertFromErpService`, `ShopifyProductCreateFromErpService`, `PurchaseOrderWorkflowPushInventoryService` (preview `erp_available_qty`).
- Pull overwrites `available_qty`: `ShopifyInventoryToProductsService::pullToAvailableQty` — must add `+ hold` when implemented.

## Cross-links

- `docs/requirements/shopify-export-and-inventory.md` — existing export/import inventory rules (predates hold).
