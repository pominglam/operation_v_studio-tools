# Plamod Preorders

**Page:** `resources/js/pages/PreordersPage.vue`  
**Route:** `/preorders` (admin nav only; hidden for employee role)  
**Spec:** `docs/requirements/plamod-preorders.md`

## Purpose

Browse Plamod retailer **new preorders** CSV snapshot: filter, search pasted kit lists, compute selling price from stock cost, and refresh from Plamod on demand or via daily cron.

## User actions

| Action | UI | API |
| --- | --- | --- |
| Browse table | Paginated grid with image, SKU (Plamod PDP link), name, costs, dates | `GET /api/v1/preorders` |
| Search | Text box + Enter / Search button | `GET /api/v1/preorders?search=` |
| New only | Checkbox | `GET /api/v1/preorders?new_only=1` |
| Refresh from Plamod | Header button; polls while queued/running | `POST /api/v1/preorders/sync`, `GET /api/v1/preorders/sync-status` |
| Exclude categories | Settings multi-select + Save | `GET/PUT /api/v1/preorders/settings` |
| Multi-line paste search | Textarea + Search lines; status text shows snapshot then live batches; three result buckets | `POST /api/v1/preorders/search-lines` (`phase`: `snapshot`, `live`, or `all`) |
| View image | Table thumbnail | `GET /api/v1/preorders/{sku}/image` |

## Computed fields

- **New badge:** Plamod SKU not present on any non-archived `products` row.
- **Unit selling price:** `price_stock × 1.5` (stock cost, not preorder cost).

## Sync behaviour

0. `POST /api/v1/preorders/sync` preflights `GET {PLAMOD_SCRAPER_URL}/health` and requires `POST /export-preorders-csv`, `POST /export-manufacturer-preorders-csv`, and `POST /search-retailer-preorders` in `routes`. Outdated scraper processes return **422** with restart guidance (no job queued).
1. On success, queues `SyncPlamodPreordersJob`.
2. Job exports **two** CSV sources and merges by SKU before import:
   - **Preorders hub:** `POST /export-preorders-csv` (`/retailer/preorders`, all tabs).
   - **Bandai manufacturer preorders:** `POST /export-manufacturer-preorders-csv` with `manufacturer_id=1` — uses filtered retailer search (`/retailer/search?manufacturers=1&categories=1&tab=preorder`, equivalent to BANDAI HOBBY → Preorder → Plastic Model Kits). Sync log records `manufacturer_row_count`, `manufacturer_has_vigna_sku`, and `manufacturer_export_error` when applicable.
3. Merged CSV import (missing rows get `dropped_at`).
4. **On every refresh:** `PlamodPreorderImageService::cleanupStaleUnlinkedImages()` deletes image files for SKUs dropped ≥ 15 days ago that are **not** linked to a non-archived product.
5. Pending images enqueue `DownloadPlamodPreorderImageJob` per SKU; UI polls sync status every 3s and reloads the table (images appear progressively).

## Table layout

- **Image** thumbnail (~80×80px).
- **Product** column stacks name (primary), SKU/barcode + New badge (secondary), series · manufacturer (tertiary)—same pattern as Products grid.

## Multi-line search

1. **Step 1 (`phase=snapshot`)** — match against imported snapshot (`plamod_preorders`, active rows, respecting excluded categories) by SKU, barcode, or product name. Returns `pending_live` lines.
2. **Step 2 (`phase=live`)** — SPA batches `pending_live` (3 lines per request) to Plamod scraper `POST /search-retailer-preorders` to avoid long single-request timeouts (e.g. HTTP 524 via tunnel).
3. Response buckets:
   - **Matched (imported):** in local snapshot.
   - **On Plamod (not in latest import):** live Plamod hit with SKU + PDP link — item may exist on Plamod but be absent from the bulk CSV export.
   - **Not found:** no snapshot or live hit.
4. Live fallback can take ~2 minutes (Playwright login + retailer search). The SPA uses a 240s request timeout for this action.

## CSV completeness note

Plamod’s **retailer search** and the **Preorders page CSV export** are not guaranteed to return the same row set. Observed on 2026-06-05:

- Manual browser CSV: **~945** rows.
- Automated export run A: **~922** rows.
- Automated export run B (same day, ~1h later): **~809** rows.

The **136-row gap** vs manual was mostly kits on Plamod’s **Offer Sheets** tab (e.g. SKU `0225768` RE 1/100 VIGNA-GHINA with PO due **2026-06-09**, ETA **Jan 2027**). The preorders page has separate tabs — **New Preorders (~815)** and **Offer Sheets (~144)** — and the CSV button exports **only the active tab**. Scraper refresh now downloads **both tabs**, merges by SKU, scroll-loads each grid, and snapshots before export.

## Settings

- Runtime key: `plamod_preorder.excluded_categories` (JSON array in `app_runtime_settings`).
- Server-side filter on index + search-lines snapshot step (not per-user).

## Schedule

- `plamod:preorders-sync` daily at 06:00 America/Toronto (`routes/console.php`).

## Ops note

After changing `node/plamod-scraper/**`, restart the scraper container so the running Node process picks up new routes:

`docker restart pricing-tool-plamod-scraper`

## Related backend

- Services: `app/Services/Plamod/PlamodPreorder*`
- Jobs: `app/Jobs/Plamod/SyncPlamodPreordersJob`, `DownloadPlamodPreorderImageJob`
- Tables: `plamod_preorders`, `plamod_preorder_sync_logs`
