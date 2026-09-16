# Reports hub

**Route:** `/reports/*`  
**Layout:** `resources/js/pages/ReportsLayoutPage.vue`  
**Nav:** **Reports** (admin only)

## Purpose

Central hub for operational reports. The left sidebar lists available reports; the main panel renders the active report via nested routes.

## Reports

| Report            | Route                             | Page                                | API                                          |
| ----------------- | --------------------------------- | ----------------------------------- | -------------------------------------------- |
| Staff orders         | `/reports/staff-orders`           | `StaffOrdersReportPage.vue`           | `GET /api/v1/reports/staff-orders`           |
| Inventory by type    | `/reports/inventory-by-main-type` | `InventoryByMainTypeReportPage.vue`   | `GET /api/v1/reports/inventory-by-main-type` |
| Customer retention   | `/reports/customer-retention`     | `CustomerRetentionReportPage.vue`     | `GET /api/v1/reports/customer-retention`     |

`/reports` redirects to **Staff orders** (default).

## Inventory by type

On-hand inventory grouped like the storefront mega menu (Model kits / Tools & Supplies / Miscellaneous), not raw ERP department. Active products only. Legacy `main_type` is not used for grouping.

| Group           | Column           | Meaning                                                                   |
| --------------- | ---------------- | ------------------------------------------------------------------------- |
| —               | **Category**     | Mega-menu shelf (grade / series / T&S job+shelf / collectible) |
| —               | **Catalog SKUs** | All active ERP products in that type                                      |
| **Received / sold** | Received     | Lifetime `qty_received` on PO lines where PO has a **received date** (Products **Total ordered**) |
| **Received / sold** | Sold         | Per-SKU `received − available_qty`, summed by type (Products **Total sold**) |
| **Received / sold** | Sold %       | `sold ÷ received` (1 decimal). Em dash when received is 0 |
| **On hand**     | SKUs             | Unique SKUs with `available_qty > 0`                                      |
| **On hand**     | Units            | Sum of `available_qty` for on-hand rows                                   |
| **On hand**     | Value (CAD)      | Sum of `available_qty × latest_landed_unit_cost` where landed cost exists |
| **On hand**     | Missing landed   | On-hand SKUs with no `latest_landed_unit_cost` (excluded from Value)      |
| **Not arrived** | SKUs             | Unique active SKUs with not-arrived PO qty &gt; 0 (includes draft POs)    |
| **Not arrived** | Units            | Sum of open PO line qty until PO is fully on shelves; includes draft POs  |
| **Not arrived** | Value (CAD)      | Sum of `not_arrived × latest_landed_unit_cost` where landed cost exists   |

The table uses a **two-row header**: **Category** and **Catalog SKUs**, then group labels (**Received / sold**, **On hand**, **Not arrived**) with sub-columns under each.

Tree (top sections start expanded; grade / job rows expand on click). Same classification as the storefront mega menu:

1. **Model kits** — Shop by grade (EG, SD + sublines, HG + sublines, RG, MG family, PG, Option parts, Action bases), then leftover series (30 Minutes, Pokémon, Kotobukiya, Keroro, SNAA, …). SD leaves match the mega menu (EX-Standard, Cross Silhouette, SD World Heroes, BB Senshi, G Generation, Build Fighters, Gunpla-kun) plus Sangoku Soketsuden from title when `subline` is blank. Action bases / option parts / 30MM rows in accessories or misc still land here when they match those shelves.
2. **Tools & Supplies** — Building / Detailing / Painting / Customization → `workshop_shelf` (Nippers & knives, Cutting mats, Sanding, Markers, Paints, …)
3. **Miscellaneous** — Keychains, CCS Toys, then leftover collectibles
4. **Other** — unset department

A third level is omitted when a mid-group has only one leaf.

**Sticky headers:** the two-row column header stays pinned to the top of the viewport while scrolling the report table.

**Scope:** active (non-archived) products only; on-hand columns use `available_qty` — **`not_arrived`** PO quantities are **not** included in on-hand counts.

**Backend:** `InventoryByMainTypeReportService` aggregates `products` by `department`, `product_line`, `workshop_shelf`, `grade`, leftover `type`, and `subline` where `archived_at` is null. **Not arrived** uses `ProductNotArrivedQtyService::sqlExpressionForProductsGrid()` — the same SQL path as the Products grid default (`not_arrived_include_draft_orders` omitted → include drafts).

**Refresh:** page **Refresh** button re-fetches the API.

**Drill-down:** unique SKU count columns link to **Products** in a new tab when the count is &gt; 0. Query uses `filters_from=url` + `archived=active`. Builder: `buildInventoryByMainTypeProductsUrl()`. Tree nodes pass **`departments[]`** plus **`product_lines[]`**, **`workshop_shelves[]`**, **`grades[]`**, **`sublines[]`**, and leftover **`types[]`** for that node.

| Column           | Products query                                                              |
| ---------------- | --------------------------------------------------------------------------- |
| Catalog SKUs     | `departments[]` + child taxonomy filter                                     |
| SKUs on hand     | same + `available_min=1`                                                    |
| Missing landed   | same + `available_min=1` + `missing_landed_cost=1`                          |
| Not arrived SKUs | same + `not_arrived_min=1` + `not_arrived_include_draft_orders=1`            |
| Footer totals    | same slice filters without department or child taxonomy                     |

Unset department rows use `departments[]=__empty__`. Quantity and dollar columns are not linked. **Received / sold** columns are unit counts only (no Products drill-down in v1).

## Customer retention

Identified Shopify people (GID / email / phone merge) with month-by-month New (acquisition) vs returning (table + Chart.js buyers/spend chart), New / Repeat / Loyal, AOV, store-rhythm cadence, own-pace 2×-gap (comparison), and store-quintile RFM. See [customer-retention.md](customer-retention.md).

## Related docs

- [staff-orders-report.md](staff-orders-report.md) — staff/channel monthly table details
- [customer-retention.md](customer-retention.md) — identified people + RFM
