# Plamod Preorders (Requirements)

## Goal

Browse Plamod **new preorders** inside the pricing tool: import the retailer CSV snapshot, show thumbnails progressively, highlight SKUs not yet in our catalog, and refresh on demand or daily.

## Data source

- Plamod retailer portal: `GET /retailer/preorders` → **CSV export** (Playwright via `plamod-scraper`).
- Full snapshot **replace** each sync; rows missing from the new CSV are marked `dropped_at`.

## Server settings (global, not per-user)

- Key: `plamod_preorder.excluded_categories` in `app_runtime_settings`.
- Value: JSON array of category names to hide from the grid.

## Selling price

- `unit_selling_price = price_stock × 1.5` (two decimal places).

## Images

- Download `Image URL` to `storage/app/private/plamod/preorder-images/{sku}.{ext}`.
- Grid usable before images finish; poll sync status and refetch rows as images complete.
- On each refresh, delete stored images when:
  - `dropped_at` is at least **15 days** ago, **and**
  - SKU does not match any non-archived `products.sku`.

## UI (`/preorders`)

- Table columns: image, new badge, SKU, barcode, product name, series, release date, manufacturer, category, stock/preorder/backorder costs, unit selling price, preorder qty, PO due date, ETA, Plamod PDP link.
- Filters: excluded categories (settings), **New only** (in Plamod, not in our catalog).
- Multi-line search paste → matched rows + **not found** list.
- **Refresh from Plamod** button; auto-refresh table while sync job runs.
- Last sync status panel.

## Schedule

- Daily sync via Laravel scheduler (`plamod:preorders-sync`).

## API (v1)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/preorders` | Paginated list |
| POST | `/api/v1/preorders/sync` | Queue refresh |
| GET | `/api/v1/preorders/sync-status` | Poll job + image progress |
| GET | `/api/v1/preorders/settings` | Excluded categories |
| PUT | `/api/v1/preorders/settings` | Save excluded categories |
| POST | `/api/v1/preorders/search-lines` | Multi-line paste search |
| GET | `/api/v1/preorders/{sku}/image` | Thumbnail |
