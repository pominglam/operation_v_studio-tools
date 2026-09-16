# Plamod Preorders (Requirements)

## Goal

Browse Plamod **new preorders** inside the pricing tool: import the retailer CSV snapshot, show thumbnails progressively, highlight SKUs not yet in our catalog, and refresh on demand or daily.

## Data source

- Plamod retailer portal: `GET /retailer/preorders` → **CSV export** (Playwright via `plamod-scraper`).
- Full snapshot merge each sync from the retailer **Preorders** hub, filtered to **Plastic Model Kits** and **Figures**: New Preorders + Offer Sheets (CSV, then JSON, then offer cards). Hub sidecar fills `plamod_preorder_offers` (offer id / PO price / dates).
- Rows missing from the merged CSV are marked `dropped_at` only after **3+ days** without `last_seen_at` (grace for incomplete exports).
- Live multi-line search upserts PDP-enriched `plamod_only` hits into the snapshot until the next refresh.

## Server settings (global, not per-user)

- Key: `plamod_preorder.excluded_categories` in `app_runtime_settings`.
- Value: JSON array of category names to hide from the grid.

## Selling price

- `unit_selling_price = OpvStandardCatalogPrice::fromCost(price_preorder ?? price_stock, OPV catalog margin)` — same as PO set-prices: closest **X.99** (ties go up). Multiplier default **1.5**, set on Maintenance.

## Images

- Download `Image URL` to `storage/app/private/plamod/preorder-images/{sku}.{ext}`.
- Grid usable before images finish; poll sync status and refetch rows as images complete.
- On each refresh, delete stored images when:
  - `dropped_at` is at least **15 days** ago, **and**
  - SKU does not match any non-archived `products.sku`.

## UI (`/preorders`)

- Table columns: image, new badge, SKU, barcode, product name, series, release date, manufacturer, category, stock/preorder/backorder costs, unit selling price, preorder qty, PO due date, ETA, Plamod PDP link.
- Filters: excluded categories (settings), **Category** multi-select, **Next / Previous category** walk, **New only** (in Plamod, not in our catalog), **Future releases only** (release date today or later), **Interest** (hide kits marked not interested by default).
- Per-SKU **Not interested** mark (`plamod_preorders.not_interested_at`) so kits you will not open drop off the default pick list; undo from the Not interested filter. Survives snapshot refresh. Opening a store offer clears the mark.
- Multi-line search paste → **`rows`** populate the main grid (imported + live PDP-enriched); only **not found** lines stay in the paste panel.
- **Refresh from Plamod** button; auto-refresh table while sync job runs.
- Last sync status panel.

## Schedule

- Daily sync via Laravel scheduler (`plamod:preorders-sync`).

## API (v1)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/preorders` | Paginated list (`interest`: `interested` default / `not_interested` / `all`) |
| POST | `/api/v1/preorders/interest` | Mark SKUs not interested or interested again |
| POST | `/api/v1/preorders/sync` | Queue refresh |
| GET | `/api/v1/preorders/sync-status` | Poll job + image progress |
| GET | `/api/v1/preorders/settings` | Excluded categories |
| PUT | `/api/v1/preorders/settings` | Save excluded categories |
| POST | `/api/v1/preorders/search-lines` | Multi-line paste search |
| GET | `/api/v1/preorders/{sku}/image` | Thumbnail |
