# Reports hub

**Route:** `/reports/*`  
**Layout:** `resources/js/pages/ReportsLayoutPage.vue`  
**Nav:** **Reports** (admin only)

## Purpose

Central hub for operational reports. The left sidebar lists available reports; the main panel renders the active report via nested routes.

## Reports

| Report            | Route                             | Page                                | API                                          |
| ----------------- | --------------------------------- | ----------------------------------- | -------------------------------------------- |
| Staff orders      | `/reports/staff-orders`           | `StaffOrdersReportPage.vue`         | `GET /api/v1/reports/staff-orders`           |
| Inventory by type | `/reports/inventory-by-main-type` | `InventoryByMainTypeReportPage.vue` | `GET /api/v1/reports/inventory-by-main-type` |

`/reports` redirects to **Staff orders** (default).

## Inventory by type

On-hand inventory grouped by **`products.type`** (Products “Type” field), nested under storefront navbar sections derived from **`products.main_type`** (active products only):

| Group           | Column           | Meaning                                                                   |
| --------------- | ---------------- | ------------------------------------------------------------------------- |
| —               | **Type**         | `products.type` (blank → “(unset)”)                                       |
| —               | **Catalog SKUs** | All active ERP products in that type                                      |
| **Received / sold** | Received     | Lifetime `qty_received` on PO lines where PO has a **received date** (Products **Total ordered**) |
| **Received / sold** | Sold         | Per-SKU `received − available_qty`, summed by type (Products **Total sold**) |
| **On hand**     | SKUs             | Unique SKUs with `available_qty > 0`                                      |
| **On hand**     | Units            | Sum of `available_qty` for on-hand rows                                   |
| **On hand**     | Value (CAD)      | Sum of `available_qty × latest_landed_unit_cost` where landed cost exists |
| **On hand**     | Missing landed   | On-hand SKUs with no `latest_landed_unit_cost` (excluded from Value)      |
| **Not arrived** | SKUs             | Unique active SKUs with not-arrived PO qty &gt; 0 (includes draft POs)    |
| **Not arrived** | Units            | Sum of open PO line qty until PO is fully on shelves; includes draft POs  |
| **Not arrived** | Value (CAD)      | Sum of `not_arrived × latest_landed_unit_cost` where landed cost exists   |

The table uses a **two-row header**: **Type** and **Catalog SKUs**, then group labels (**Received / sold**, **On hand**, **Not arrived**) with sub-columns under each.

Storefront navbar sections and order (via each row’s `main_type`):

1. **Model kits** — `model kit`
2. **Tools & Supplies** — `tools`, `supplies`, `paints`
3. **Water decals** — `water decals`
4. **Miscellaneous** — `misc`
5. **Other** — any unrecognized or unset main type

Each storefront section is an expandable group row showing aggregate values for its child types. Groups start expanded. When the same **type** label appears under multiple **main_type** values inside a group (e.g. `PAINT` under `tools`, `supplies`, and `paints`), the UI merges those into one row and drill-down passes every matching `main_types[]` + `types[]` pair. The Type sort preserves navbar section order by default; sorting another column orders groups and their children by that metric. Child count links drill down with the exact `products.type` value.

**Sticky headers:** the two-row column header stays pinned to the top of the viewport while scrolling the report table.

**Scope:** active (non-archived) products only; on-hand columns use `available_qty` — **`not_arrived`** PO quantities are **not** included in on-hand counts.

**Backend:** `InventoryByMainTypeReportService` aggregates `products` by `type` and `main_type` where `archived_at` is null. **Not arrived** uses `ProductNotArrivedQtyService::sqlExpressionForProductsGrid()` — the same SQL path as the Products grid default (`not_arrived_include_draft_orders` omitted → include drafts).

**Refresh:** page **Refresh** button re-fetches the API.

**Drill-down:** unique SKU count columns link to **Products** in a new tab when the count is &gt; 0: **Catalog SKUs**, **SKUs on hand**, **Missing landed**, and **Not arrived SKUs** (footer totals too). Query uses `filters_from=url` + `archived=active` (+ filters per column below) so the URL defines filters — saved Products `pageState` is **not** loaded or persisted for that tab. Builder: `buildInventoryByMainTypeProductsUrl()` (`resources/js/lib/inventoryByMainTypeProductsLinks.ts`). Type rows pass both **`main_types[]`** (navbar bucket / `products.main_type`) and **`types[]`** (subtype).

| Column           | Products query                                                              |
| ---------------- | --------------------------------------------------------------------------- |
| Catalog SKUs     | `main_types[]` + `types[]`                                                  |
| SKUs on hand     | `main_types[]` + `types[]` + `available_min=1`                              |
| Missing landed   | `main_types[]` + `types[]` + `available_min=1` + `missing_landed_cost=1`    |
| Not arrived SKUs | `main_types[]` + `types[]` + `not_arrived_min=1` + `not_arrived_include_draft_orders=1` |
| Footer totals    | same slice filters without `main_types[]` or `types[]`                      |

Unset main type rows use `main_types[]=__empty__`; unset type rows use `types[]=__empty__`. Quantity and dollar columns are not linked. **Received / sold** columns are unit counts only (no Products drill-down in v1).

## Related docs

- [staff-orders-report.md](staff-orders-report.md) — staff/channel monthly table details
