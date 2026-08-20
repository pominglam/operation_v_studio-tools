# PLAMOD restock (`/restocking/plamod`)

**Page:** `resources/js/pages/PlamodRestockPage.vue`  
**Nav:** **Restock** (after Preorders)

Weekly **in-stock restock** proposal for Bandai Hobby Plastic Model Kits on PLAMOD, intersected with ERP reorder needs. Separate from **`/preorders`** (new preorders).

An item is an **existing product** whenever its SKU already exists in the active ERP catalog and in the PLAMOD in-stock snapshot, regardless of the ERP product’s historical/current vendor. Vendor does not change product identity; only a PLAMOD in-stock SKU absent from ERP is classified under **New on PLAMOD**.

---

## Data source

- PLAMOD retailer manufacturer export: **Bandai (`manufacturer_id=1`) · category Plastic Model Kits · tab In-Stock**
- Playwright via `plamod-scraper` → CSV → `plamod_instock_items` (DB snapshot)
- Manual refresh only (no scheduler): **Maintenance** card + **Refresh from PLAMOD** on this page
- Export discovers **BRAND** sidebar filters on the In-Stock tab (without pre-selecting Plastic Model Kits — that collapses the filter list), runs the same per-filter export path as single-series exports (PMK skipped when a sub-filter is set; **BRAND** tab filters use brand selection directly), re-authenticates before each chunk, merges rows by SKU, then imports when ≥85% of the In-Stock tab badge count
- Last successful sync shown in page header (`plamod_instock_sync_logs`); green banner after refresh shows **imported vs expected** (e.g. `708 of ~709 SKUs (99.9%)`)

---

## Section A — Existing products

- ERP products with `vendor = Plamod` whose SKU appears in the PLAMOD in-stock snapshot (**all intersecting rows**, not only `reorder > 0`)
- Use this table to review/update **maintain qty** even when suggested reorder is zero
- Reorder formula: `max(0, Maintain − Available − Not arrived)` with **draft POs excluded** from Not arrived
- Section summary shows how many **unique visible products** have a positive system suggestion and the sum of their **Suggested** quantities; it uses `reorder_qty` (not operator-overridden order qty) and follows search/type filters
- **Suggested** column shows the formula result; **Order qty** is an editable override (persisted in `plamod_restock_reorder_overrides`, highlighted when overridden, **Reset** clears override). Default order qty matches suggested (zero when no reorder need).
- Draft PO creation still **skips existing lines with order qty 0**; restock totals include only lines with qty &gt; 0
- **Maintain** is editable inline (`PATCH /api/v1/products/{uuid}/maintain`); saves on change and reloads proposal
- Column headers use **ⓘ help buttons** (click to open tooltip popover) for Available, Maintain, Not arrived, Preorders, Suggested, Order qty, New cost
- **Not arrived** = sum of `qty_ordered` on PO lines until the PO is fully on shelves (`fully_on_shelves_date` null); received-but-not-shelved quantities remain included; **draft POs excluded** (must have ordered or shipped date)
- **Preorders** = qty already committed on your PLAMOD account (`plamod_preorders` / `plamod_preorder_offers`); hover or click the cell to see per-offer ETA breakdown (distinct from Not arrived)
- **Product type** comes from canonical ERP `products.type` (the same values operators use in PLAMOD’s Type facet); it is exposed as a sortable column
- **Search existing products** filters only the existing-products grid by SKU, barcode, or product name; it can be combined with Product type and persists in `plamod_restock_page_state`
- **Product type filter** isolates existing rows by one exact type and persists in `plamod_restock_page_state`
- While a type is selected, a **type budget** strip shows visible SKU count, proposed units, product subtotal, landed subtotal, and missing-price count; product subtotal sums each row’s proposal `line_total.product`, then landed applies the configured shipping percentage once
- **Sortable columns** on existing-products grid (client-side)
- Sticky table header while scrolling within the existing-products panel
- Product column: PLAMOD PDP link + **Open in ERP** (`/products?search={sku}`)
- **Last cost** and **New cost** columns show **product cost only** (no per-line shipping)
- **New cost** amber highlight + **↑/↓ %** when product cost changed **> 3%** vs last product cost
- **Line total** = order qty × **new product cost only** (no shipping)
- **Search** (above tables): client-side filter on SKU, barcode, or product name — applies to both existing and new tables; section headers show `N of M rows` while filtered

---

## Restock totals

- Summary above the tabs splits **Total**, **Existing products**, and **New products** into units, product cost, shipping estimate, and landed total
- The total reconciles the existing and new-product breakdowns; each group applies the configured shipping percentage to its own product subtotal
- Includes existing lines using **order qty** (override or suggested) plus **included** new SKUs
- Line totals and product subtotal exclude per-line shipping; shipping is applied once at summary level
- Lines with missing PLAMOD price are excluded from dollar totals (count shown in warning)

---

## Section B — New on PLAMOD

- In-stock SKUs **not** in ERP catalog
- Existing and new products use separate tabs (existing is the default); the active tab persists in `plamod_restock_page_state`, while cart and bulk selections survive tab switches
- **Dismiss** (persisted per SKU), **Later** (deferred backlog — visible in list; select the **Later** status filter to review), or **Include** (requires non-negative order qty + planned maintain qty; include dialog defaults qty fields **blank**)
- **Exclude** on included rows → **dismissed** (clears qtys; hidden when hide dismissed is on)
- **Include from dismissed or later** — same Include flow (no separate undismiss)
- Planned maintain is stored in **`plamod_restock_planned_maintain`** (not on draft PO lines); applied to `products.maintain_qty` when the SKU is first created during **PO import**
- **New-section filters**: hide dismissed (default on), multi-select statuses (**undecided**, **later**, **dismissed**, **included**), recent releases, series dropdown
- Selected statuses use union/OR matching while recent release, series, and search continue to narrow with AND matching. Selecting **dismissed** explicitly includes dismissed rows even if **Hide dismissed** remains checked.
- **Included** alone reloads the proposal with `only_included_new`; combining it with another status reloads the complete proposal so all selected statuses can appear (the filters do not affect the existing-products table)
- **Bulk include / bulk later / bulk dismiss** via the visibly labeled **Bulk** checkbox column + action bar
- **Always hide future products** persists exact, case-insensitive series rules and case-insensitive product-name contains rules. Matching catalog-less SKUs are treated as dismissed automatically, including SKUs discovered by later PLAMOD refreshes. Removing a rule requires confirmation; an explicit per-SKU **Later** or **Include** decision overrides the automatic rule.
- **Sortable columns** (client-side); default sort **release date descending** (recent first)
- Sticky table header; **pageState** key `plamod_restock_page_state` persists the active tab, search, hide dismissed, new-section filters (including included only), and sort for both tables
- Columns: image thumbnail (click → lightbox overlay), SKU + barcode, product (PLAMOD PDP link when URL known), series, category, release, status, order qty, planned maintain, new cost, line total, actions
- **Included** rows: inline edit order qty + planned maintain (saves on change). Order qty `0` is valid and keeps the product included for tracking while excluding it from totals, normal cart selection, and draft PO lines. Full-order verification still sends it as an explicit zero target, so a remaining PLAMOD cart quantity is shown as **over-added** and **Fix mismatches** can remove it.
- Column **ⓘ help** on status, order qty, planned maintain, new cost
- Missing PLAMOD price: amber highlight on new cost + section warning (`meta.new_missing_price_count`) — treat as sync/scraper gap; refresh from PLAMOD
- Included rows are added to draft PO as **catalog-less lines** (`product_id` null) until PO import creates products
- **Restock cost breakdown** shows unique ordered products, units, product cost, shipping estimate, and landed total for Total, Existing products, and included New products; zero-quantity and non-included new rows are excluded

---

## Actions

| Action                         | API                                                                                                                                                                                                                  |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Load proposal                  | `GET /api/v1/plamod/restock/proposal`                                                                                                                                                                                |
| Save restock settings          | `PUT /api/v1/plamod/restock/settings` body `{ shipping_percent, excluded_series?, excluded_product_terms? }`; omitted exclusion arrays preserve current rules                                                        |
| Refresh snapshot               | `POST /api/v1/plamod/restock/sync`                                                                                                                                                                                   |
| Poll sync                      | `GET /api/v1/plamod/restock/sync-status` — while running, includes scraper progress (`phase`, `filters_processed` / `filters_total`, current filter name, PDP enrich counts)                                         |
| Dismiss / later / include SKU  | `PUT /api/v1/plamod/restock/decisions/{sku}` body `{ status: dismissed \| later \| included, ... }`                                                                                                                  |
| Bulk dismiss / later / include | `POST /api/v1/plamod/restock/decisions/bulk` body `{ skus[], status, order_qty?, planned_maintain_qty? }`                                                                                                            |
| Override existing order qty    | `PUT /api/v1/plamod/restock/reorder-overrides/{sku}` body `{ reorder_qty: number \| null }`                                                                                                                          |
| Create draft PO                | `POST /api/v1/plamod/restock/draft-purchase-order` → redirects to PO detail                                                                                                                                          |
| Add to PLAMOD cart             | `POST /api/v1/plamod/restock/cart-run` body `{ skus: string[] }` (required, min 1) — queues Playwright job for **selected** lines only (existing **order qty** &gt; 0 + **included** new SKUs with order qty &gt; 0) |
| Poll cart run                  | `GET /api/v1/plamod/restock/cart-run-status` — while running merges scraper progress; on completion includes **verification report**                                                                                 |
| Recheck cart                   | `POST /api/v1/plamod/restock/cart-run-recheck` — re-scrapes PLAMOD cart against the latest run's stored `cart_before` baseline; updates report without re-adding                                                     |

Draft PO feedback includes counts for existing lines added, new catalog-less lines, skipped, undecided, and dismissed.

---

## Phase 2 — PLAMOD cart automation

After reviewing the proposal, select rows with the visibly labeled **Cart** checkboxes (first column on existing products; second column on new products when included with qty &gt; 0). **Set selected in PLAMOD cart** in the header opens a confirm dialog, then queues a background job on `plamod_sync`:

1. Builds the same line list as draft PO creation (`PlamodRestockCartLineBuilder`).
2. `plamod-scraper` snapshots cart **before** and skips exact already-satisfied lines. Missing lines are opened in a fresh PDP tab (separate from the baseline cart tab), then the PACK `+` action creates the cart line. Existing partial or over-added lines are changed with that SKU row's cart `−`/`+` controls; the scraper re-finds the virtualized row and re-reads its quantity after every click.
3. **Does not clear the cart**. An existing line is stepped to its requested total rather than using the PDP add control, so retries cannot double-add and over-added quantities can be lowered.
4. Performs one cart quantity pass and one final reload/snapshot after all PDP updates instead of a cart round-trip per SKU. Baseline reads retry with a fresh cart tab when PLAMOD transiently renders an empty cart document. Recheck always reloads PLAMOD and never falls back to the previous tab snapshot. A refreshed cart with rows (even when requested SKUs are absent) or an explicit empty-cart screen is authoritative; an unexplained blank render returns an inconclusive error instead of cached or fabricated quantities.
5. Persists run + report in `plamod_restock_cart_runs` (`counts_json.report`).

### Verification report (UI + API)

When the run finishes, the page shows a headline summary and per-line table:

| Field         | Meaning                                                                           |
| ------------- | --------------------------------------------------------------------------------- |
| **Requested** | Qty from restock proposal                                                         |
| **Added**     | Observed cart qty increase for that SKU                                           |
| **Cart qty**  | Total qty in cart after run                                                       |
| **Result**    | `verified`, `already_satisfied`, `partial`, `over_added`, `missing`, `add_failed` |

**`all_verified`** is true only when every line is `verified` or `already_satisfied`. Incomplete verification sets `error_summary` (e.g. `2/5 verified, 1 partial…`) while status remains `completed` so operators see the report without opening PLAMOD.

Progress while running: phase, `items_processed` / `items_total`, current SKU (from scraper progress file). Link to [PLAMOD cart](https://plamod.com/retailer/cart) for manual spot-check if needed.

**Recheck PLAMOD cart** (report panel button) calls `POST /api/v1/plamod/restock/cart-run-recheck` when a verification report exists and no cart job is running. Use after PLAMOD finishes updating or when a line was falsely marked missing.

**Retry remaining** (report panel, visible when any line is `missing`, `add_failed`, `partial`, or `over_added`) queues another cart run for those SKUs only. It snapshots current cart totals and sets each existing line to the exact requested final quantity with PLAMOD's cart-row quantity steppers: partial lines are increased and over-added lines are lowered.

When PLAMOD rejects or cannot offer the requested quantity, the line displays **PLAMOD message:** followed by the retailer response or constraint (for example, its MOQ). Rechecking preserves that message while the mismatch remains and clears it once the line verifies.

**Dismiss** hides the latest completed report and headline in that browser until a newer run exists. Reloading during a queued/running job resumes polling automatically. Refresh-from-PLAMOD is disabled while cart automation owns the scraper session.

Scraper routes: `POST /restock-add-to-cart`, `POST /restock-verify-cart`, `GET /restock-cart-progress`.

**Qty discipline:** for **New on PLAMOD** products, `order_qty` is the desired combined quantity, so `required IN-STOCK = max(0, planned qty − PREORDER ARRIVED qty)`. For existing products, the suggestion already subtracts open-PO inbound quantity, so its planned qty remains the required additional IN-STOCK quantity. Final IN-STOCK qty equal to the calculated requirement is `verified` (or `already_satisfied` when exact before the run); below is `partial`/`missing`; above is `over_added`.

### Full order verification (all order lines)

**Verify full order (N)** in the page header compares each calculated **required IN-STOCK quantity** against the live PLAMOD cart. Included new products with order qty `0` remain verification lines with a zero target instead of being reclassified as unrelated extra cart lines. For included new products, PLAMOD **PREORDER ARRIVED** quantity counts toward the planned quantity; for existing products, open-PO inbound has already been deducted by the proposal formula. The report shows planned, arrived-preorder, required-IN-STOCK, and actual-IN-STOCK quantities separately. This is separate from normal cart selection, which only enables SKUs with qty &gt; 0. **Fix mismatches** uses the same calculation and converges each SKU’s IN-STOCK cart quantity—including explicit zero targets—to the requirement rather than blindly adding the planned quantity.

| Control                  | Behavior                                                                                                                                       |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| **Verify full order**    | `POST /api/v1/plamod/restock/order-verify` — scrapes PLAMOD cart once and compares each order line’s qty                                       |
| **Verify again**         | Re-runs the same full-order check                                                                                                              |
| **Show mismatches only** | Hides verified rows in the report table                                                                                                        |
| **Product name**         | Opens that SKU’s PLAMOD product detail page in a new tab for manual correction                                                                 |
| **Arrived preorder qty** | Qty in PLAMOD’s `PREORDER ARRIVED` block; counts toward a new product’s planned total                                                          |
| **Required in-stock qty** | Target IN-STOCK cart qty after applying arrived preorders for new products                                                                     |
| **PLAMOD message**       | A still-mismatched row repeats the latest retailer constraint/error captured by **Fix mismatches**; the message clears after the line verifies |
| **Extra cart lines**     | SKUs present in PLAMOD cart but **not** in your order (common when the cart was not cleared)                                                   |

**Pass condition:** `order_matches_cart` is true only when every order line is `verified`/`already_satisfied` **and** there are **no extra cart lines**. The headline turns green when the cart exactly matches your intended order.

Last report persists in `app_runtime_settings` key `plamod_restock.order_verify_report` and reloads via `GET /api/v1/plamod/restock/order-verify`. Dismiss hides it in the current browser until you verify again.

Blocked while a cart automation job is queued/running or when the scraper is unavailable. The scraper scrolls PLAMOD’s virtualized cart list before reading quantities (large carts are not fully present in the DOM on first paint). IN-STOCK cart quantities are read from the structured quantity control / `TOTAL` value. PREORDER ARRIVED quantities are read from the numeric value immediately before the `ORDERED` label, and only from a container whose product links all resolve to that one SKU (preventing a neighboring row’s preorder from bleeding into the result). Flattened row text is not used because adjacent controls can concatenate `2` and `0` into a false `20`.

**Performance and safety:** cart operations are serialized and use a dedicated persistent-profile directory for authentication, but the Playwright browser context itself is newly launched and closed for every add/recheck. Shopping-cart DOM/state is never reused between operations. Fresh-tab sign-in detection is bounded (it never waits Playwright's 30-second default for an optional heading), the flow avoids cart-page round trips between SKUs, and it verifies once per run. `PLAMOD_RESTOCK_CART_PROFILE_DIR` defaults to `.pw-user-data-cart` and is configured as `/app/.pw-user-data-cart` in Compose, preventing cart automation from locking the general scraper profile. Other optional env: `PLAMOD_RESTOCK_CART_ACTION_TIMEOUT_MS` (default 8,000), `PLAMOD_RESTOCK_CART_CART_SETTLE_MS` (default 150).

---

## Settings

- **Shipping estimate %** (default 5%) persisted in `app_runtime_settings` key `plamod_restock.shipping_percent`
- Used for new landed cost estimates on the proposal and draft PO shipping total
- **Always-hidden series** persisted as JSON in `app_runtime_settings` key `plamod_restock.excluded_series`
- **Always-hidden product-name terms** persisted as JSON in `app_runtime_settings` key `plamod_restock.excluded_product_terms`

---

## Maintenance

- **Refresh PLAMOD in-stock catalog** card on `/maintenance` (same sync endpoint)

### Scraper performance (instock merged export)

- Reuses one Playwright session from filter discovery through export (no mid-run browser restart).
- Sidebar filter list is cached on disk when the in-stock tab badge total is unchanged (`PLAMOD_INSTOCK_FILTER_CACHE`, default on; TTL `PLAMOD_INSTOCK_FILTER_CACHE_TTL_MS`, default 24h).
- Filter slices with `manufacturerCategoryId` navigate directly (skip listing-page reset between chunks).
- Per-slice listing price retry runs only when ≥50% of rows in the slice lack `price_stock`; sparse gaps defer to the global PDP enrich pass.
- Listing cards with **IN-STOCK + OFFER CLOSED + PRICE** (common on 30MF lines) parse `price_stock` from the full product card, not the SKU column only.
- PDP enrich reacquires the browser session after Playwright crashes instead of skipping the remaining SKUs.

---

## Related scraper endpoints

| Endpoint                                   | Purpose                 |
| ------------------------------------------ | ----------------------- |
| `POST /export-manufacturer-instock-merged` | In-stock catalog sync   |
| `GET /instock-export-progress`             | Sync progress           |
| `POST /restock-add-to-cart`                | Phase 2 cart automation |
| `GET /restock-cart-progress`               | Cart run progress       |
