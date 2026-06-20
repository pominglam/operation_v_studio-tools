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
| Bandai series filters | Three columns (Not decided / Included / Excluded); Include / Exclude / Undecided per row; Refresh series list | `GET /api/v1/preorders/manufacturer-filters`, `POST …/discover`, `PUT …/manufacturer-filters` |
| Multi-line paste search | Textarea + Search lines; status text shows snapshot then live batches; three result buckets | `POST /api/v1/preorders/search-lines` (`phase`: `snapshot`, `live`, or `all`) |
| View image | Table thumbnail | `GET /api/v1/preorders/{sku}/image` |

## Computed fields

- **New badge:** Plamod SKU not present on any non-archived `products` row.
- **Stock $ column:** `price_preorder` (PO cost) when present, else `price_stock`.
- **Unit selling price:** `CharmPricingCalculator::sellingPriceX99FromCost` on `(price_preorder ?? price_stock)` at **1.5×**, rounded up to the next **X.99** (same as PO set-prices workflow).
- **Release:** Plamod PDP **Release Date** (e.g. May 1, 2018).
- **PO due:** Plamod preorder offer **Closing** date (e.g. Jun 9 → `po_due_date`).
- **ETA:** Plamod offer **ETA** (e.g. JAN 31 → `eta_date`; month-day-only values infer year at import).

## Sync behaviour

0. `POST /api/v1/preorders/sync` preflights `GET {PLAMOD_SCRAPER_URL}/health` and requires `POST /export-preorders-csv`, `POST /export-manufacturer-preorders-csv`, `POST /list-manufacturer-preorders-filters`, and `POST /search-retailer-preorders` in `routes`. Outdated scraper processes return **422** with restart guidance (no job queued).
1. On success, queues `SyncPlamodPreordersJob`.
2. Job discovers Bandai manufacturer sidebar filters (`list-manufacturer-preorders-filters`), upserts `plamod_preorder_manufacturer_filters`, then exports CSV sources and merges by SKU before import:
   - **Preorders hub:** `POST /export-preorders-csv` (`/retailer/preorders`, all tabs) — always runs.
   - **Bandai manufacturer preorders:** one `POST /export-manufacturer-preorders-csv` per filter row with decision **include** only (`manufacturer_id=1`, plus `series` or `category_line`). **Undecided** and **exclude** rows are not pulled. If no rows are included, hub-only import still runs.
   - Sync log records `manufacturer_pull_count`, `manufacturer_row_count`, `manufacturer_export_errors`, and per-source paths when applicable.
3. Merged CSV import (missing rows get `dropped_at`).
4. **On every refresh:** `PlamodPreorderImageService::cleanupStaleUnlinkedImages()` deletes image files for SKUs dropped ≥ 15 days ago that are **not** linked to a non-archived product.
5. Pending images enqueue `DownloadPlamodPreorderImageJob` per SKU; UI polls sync status every 3s and reloads the table (images appear progressively).

### Live sync progress (`GET /api/v1/preorders/sync-status`)

While status is `queued` or `running`, `counts_json.phase` drives the amber progress panel on `/preorders` (polled every 3s):

| Phase | UI label |
| --- | --- |
| `discover` | Discovering manufacturer filters… |
| `hub_export` | Exporting hub preorders… |
| `manufacturer_export` | Exporting manufacturer filters (`processed/total`) + current filter name |
| `manufacturer_recovery` | Retrying failed filters (`processed/total`) + current filter name |
| `import` | Merging and importing rows… |
| `images` | Downloading images (`done/total`) with progress bar |

Live counters during manufacturer export: `manufacturer_filters_processed`, `manufacturer_filters_total`, `manufacturer_current_filter`, `manufacturer_export_succeeded`, `manufacturer_export_failed`.

## Table layout

- **Image** thumbnail (~80×80px).
- **Product** column stacks name (primary), SKU/barcode + New badge (secondary), series · manufacturer (tertiary)—same pattern as Products grid.

## Multi-line search

1. **Step 1 (`phase=snapshot`)** — match against imported snapshot (`plamod_preorders`, active rows, respecting excluded categories) by SKU, barcode, or product name. Returns `pending_live` lines.
2. **Step 2 (`phase=live_start` / `live_poll`)** — SPA queues one background job for all `pending_live` lines (`RunPlamodPreorderLiveSearchJob`), then polls every 3s. This avoids Cloudflare tunnel HTTP 524 timeouts on long Playwright searches.
3. Response includes **`rows`** (full grid-shaped preorder resources, paste-line order) plus **`not_found`** lines only in the multi-line panel.
   - Imported matches: loaded from `plamod_preorders`.
   - **Plamod only** (not in latest import): live search + PDP enrich; grid rows carry `not_in_import: true` and a **Plamod only** badge.
4. After **Search lines**, the main table switches to paste results (pagination hidden). **Clear and show all preorders** restores the normal index.
5. Live fallback runs in the queue worker (one warm Playwright session; PDP enrich per hit). Poll requests use a 30s timeout; the UI may show “Searching Plamod live (N lines in background)…” for several minutes.

## Sync retries and failure logging

- Hub CSV and each **included** manufacturer series export retry on **login / timeout / connection** errors (hub: 2 attempts; series: 3 attempts + one recovery pass after session reset).
- Scraper endpoint `POST /reset-scraper-sessions` closes warm Playwright sessions between retries.
- **Self-healing:** serial sync jobs checkpoint hub + completed manufacturer CSV paths in `counts_json`. If a chained step dies (timeout, restart, max attempts), `PlamodPreorderSyncAutoResume` requeues `SyncPlamodPreordersJob` up to **5** times after 45s and **resumes** from the last completed filter (queue worker `--timeout=21600`, `--max-time=0`). UI shows `Auto-resuming sync (attempt N/5)…`.
- **Serial job chain (no parallel Plamod hits):** `SyncPlamodPreordersJob` → `Bus::chain` on queue **`plamod_sync`**: hub export → one job per **included** series → recovery pass → merge/import/images finalize. Image downloads still queue separately on `default`.
- Each failed step appends a JSON line to `storage/app/private/plamod/preorder_sync_logs/sync-{id}-failures.jsonl` and emits a Laravel warning log.
- Sync status `counts` includes `manufacturer_export_succeeded`, `manufacturer_export_failed`, `manufacturer_export_retried`, `failure_summary` (grouped by error kind), and `failure_log_path`. The Preorders page shows a summary panel when manufacturer exports partially fail.

## Crawl coverage (Refresh from Plamod)

Each refresh merges:

1. **Hub CSV** — `/retailer/preorders`, both **New Preorders** and **Offer Sheets** tabs. If the downloaded CSV has fewer rows than visible product links after scroll-load, the scraper **supplements** missing SKUs from the on-screen grid.
2. **Bandai manufacturer pulls** — every **included** series/category line is exported (no longer skipped when the sidebar preorder count is `0`; badge counts can be stale).
3. **Drop grace** — SKUs missing from an incomplete merged CSV are not marked `dropped_at` until they have been absent for **3+ days** (`last_seen_at`), so one bad export does not wipe the snapshot.

Live multi-line search still PDP-enriches gaps and **upserts** `plamod_only` hits into `plamod_preorders` so the next refresh can merge fuller CSV data over them.

## CSV completeness note

Plamod’s **retailer search** and the **Preorders page CSV export** are not guaranteed to return the same row set. Observed on 2026-06-05:

- Manual browser CSV: **~945** rows.
- Automated export run A: **~922** rows.
- Automated export run B (same day, ~1h later): **~809** rows.

The **136-row gap** vs manual was mostly kits on Plamod’s **Offer Sheets** tab (e.g. SKU `0225768` RE 1/100 VIGNA-GHINA with PO due **2026-06-09**, ETA **Jan 2027**). The preorders page has separate tabs — **New Preorders (~815)** and **Offer Sheets (~144)** — and the CSV button exports **only the active tab**. Scraper refresh now downloads **both tabs**, merges by SKU, scroll-loads each grid, and snapshots before export.

## Settings

- Runtime key: `plamod_preorder.excluded_categories` (JSON array in `app_runtime_settings`).
- Server-side filter on index + search-lines snapshot step (not per-user).

## Bandai manufacturer series filters

- Table: `plamod_preorder_manufacturer_filters` — one row per discovered Plamod sidebar **series** or SD **category line** (Tier 2b) for manufacturer id `1` (BANDAI HOBBY).
- Decision: `undecided` (default for new discoveries), `include`, or `exclude`. Only **include** rows are scraped on refresh; decisions can be changed anytime.
- **Refresh series list** — `POST /api/v1/preorders/manufacturer-filters/discover` queues a background job (returns `job_id` immediately); SPA polls the same endpoint with `{ job_id }` every 3s to avoid Cloudflare HTTP 524 on long Playwright scrapes. On completion, upserts counts; bootstrap defaults tier Gundam / 30MM / Pokémon / etc. to **include** and known noise IPs to **exclude**.
- UI: three columns on Preorders settings panel — Not decided, Included, Excluded — with action buttons per row.

## Schedule

- **Daily cron disabled (2026-06-18):** `plamod:preorders-sync` at 06:00 America/Toronto is commented out in `routes/console.php` until sync is stable. Manual run: **Sync preorders** on `/preorders` or `php artisan plamod:preorders-sync`.

## Ops note

After changing `node/plamod-scraper/**`, restart the scraper container so the running Node process picks up new routes:

`docker restart pricing-tool-plamod-scraper`

## Related backend

- Services: `app/Services/Plamod/PlamodPreorder*`
- Jobs: `app/Jobs/Plamod/SyncPlamodPreordersJob`, `ExportPlamodPreorderHubCsvJob`, `ExportPlamodManufacturerFilterJob`, `RecoverFailedPlamodManufacturerFiltersJob`, `FinalizePlamodPreorderSyncJob`, `DownloadPlamodPreorderImageJob`
- Tables: `plamod_preorders`, `plamod_preorder_sync_logs`, `plamod_preorder_manufacturer_filters`
