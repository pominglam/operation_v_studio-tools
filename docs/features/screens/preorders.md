# Plamod Preorders

**Page:** `resources/js/pages/PreordersPage.vue`  
**Route:** `/preorders` (admin nav **Procurement → Plamod preorders**; hidden for employee role)  
**Spec:** `docs/requirements/plamod-preorders.md`

## Purpose

Browse the Plamod retailer **kits hub** snapshot (New Preorders + Offer Sheets): filter, search pasted kit lists, compute selling price from PO cost, and refresh from Plamod. This is the **distributor pick list**, not customer store pre-orders. Open selected kits as store offers from here (see [store-preorders.md](store-preorders.md)).

## User actions

| Action | UI | API |
| --- | --- | --- |
| Browse table | Paginated grid with Open checkbox, image, SKU (Plamod PDP link), name, costs, dates, store-offer badge | `GET /api/v1/preorders` |
| Search | Text box + Enter / Search button | `GET /api/v1/preorders?search=` |
| New only | Checkbox. Keeps kits whose Plamod SKU is **not** on any non-archived ERP `products` row (same rule as the **New** badge). Already-cataloged SKUs drop off. Independent of leftovers / store-offer filters. | `GET /api/v1/preorders?new_only=1` |
| Future releases only | Checkbox. Keeps kits whose Plamod **release date** is today or later (America/Toronto). Blank release dates drop off. Independent of leftovers / store-offer / new-only. | `GET /api/v1/preorders?future_releases_only=1` |
| Live window (default) | Only kits with a Plamod **preorder price**, not on the current in-stock list, whose closing date is today/future or blank. Stock-only catalog leftovers are excluded. | omitted `include_closed` |
| Show leftovers | Optional checkbox for in-stock, closed, or stock-only listings | `GET /api/v1/preorders?include_closed=1` |
| Store offer | Not opened / Already opened / All | `GET /api/v1/preorders?store_offer=` |
| Interest | Interested (default) / Not interested / All. Mark kits you will not open; they drop off the default list until you undo. | `GET /api/v1/preorders?interest=` · `POST /api/v1/preorders/interest` |
| Not interested | Header button for selected rows, or per-row link. Survives Plamod refresh. Opening a kit as a store offer clears the mark. | `POST /api/v1/preorders/interest` |
| Category | Multi-select with kit counts; inspect one or more categories. Hidden (excluded) categories stay listed as muted so you can review them. | `GET /api/v1/preorders?categories[]=` |
| Next / Previous category | Walk live (non-excluded, count &gt; 0) categories one at a time | `GET /api/v1/preorders?categories[]=` |
| Hide selected categories | Adds the current Category filter to excluded settings and saves; then advances to the next live category | `PUT /api/v1/preorders/settings` |
| Sort | Click a column header (Product, Release, ETA date, ETA **Mo**, Category, Stock $, Sell $, PO qty, PO due). Click again to reverse. Default is PO due ascending (closing soon). Image / Open checkbox are not sortable. | `GET /api/v1/preorders?sort=&sort_dir=` |
| Open & push to Shopify | Header button after checking rows; confirm lists each kit with Sell $, landed (PO cost + restock shipping %), live multiplier, deposit %, cap, and pre-filled closing (Plamod due − 1 day). Confirm scrapes the Plamod PDP full description, queues Shopify publish (photos follow the Plamod crawl), and clears any **Not interested** mark. | `POST /api/v1/store-preorders` |
| View store preorders | Header link to `/store-preorders` | — |
| Refresh from Plamod | Header button; polls while queued/running. Also runs daily at **06:00 America/Toronto** (`plamod:preorders-sync`); skips if a refresh is already queued or running. | `POST /api/v1/preorders/sync`, `GET /api/v1/preorders/sync-status` |
| Exclude categories | Settings multi-select (counts) + Save | `GET/PUT /api/v1/preorders/settings` |
| Bandai series filters | Three columns (Not decided / Included / Excluded); Include / Exclude / Undecided per row; Refresh series list | `GET /api/v1/preorders/manufacturer-filters`, `POST …/discover`, `PUT …/manufacturer-filters` |
| Multi-line paste search | Textarea + Search lines; status text shows snapshot then live batches; three result buckets | `POST /api/v1/preorders/search-lines` (`phase`: `snapshot`, `live`, or `all`) |
| View image | Table thumbnail | `GET /api/v1/preorders/{sku}/image` |

## Computed fields

- **New badge:** Plamod SKU not present on any non-archived `products` row.
- **Stock $ column:** `price_preorder` (PO cost) when present, else `price_stock`.
- **Unit selling price:** estimated landed (`(price_preorder ?? price_stock)` + Plamod restock shipping %, default **5%**) × Maintenance **OPV catalog margin** (default **1.5×**), then the **closest X.99** (ties go up). Same snap as PO set-prices; the 1.5× target is vs landed, not raw PO cost.
- **Release:** Plamod PDP **Release Date** (e.g. May 1, 2018).
- **PO due:** Plamod preorder offer **Closing** date (e.g. Jun 9 → `po_due_date`).
- **ETA:** Plamod offer **ETA** (e.g. JAN 31 → `eta_date`; month-day-only values infer year at import). Under the date, **Mo** is calendar months from release to ETA (`eta_lead_months`; both dates required). Negative when ETA is before release. Sort the date with **ETA**, or the gap with **Mo**.

## Sync behaviour

0. `POST /api/v1/preorders/sync` preflights `GET {PLAMOD_SCRAPER_URL}/health` and requires `POST /export-preorders-csv`, `POST /export-manufacturer-preorder-merged`, `GET /preorder-export-progress`, `POST /list-manufacturer-preorders-filters`, and `POST /search-retailer-preorders` in `routes`. Outdated scraper processes return **422** with restart guidance (no job queued).
1. On success, queues `SyncPlamodPreordersJob`.
2. Job discovers Bandai sidebar filters (for the settings UI), then exports CSV sources and merges by SKU before import:
   - **Preorders hub (catalog source):** `POST /export-preorders-csv` on `/retailer/preorders`, filtered to **Plastic Model Kits** and **Figures** (exported separately, then merged by SKU). Exports **New Preorders** and **Offer Sheets** via CSV, then JSON, then offer-card harvest (SKU, PO price, offer id, ETA, closing).
   - Manufacturer merged export is **not** chained on refresh; kits on this hub page are the intended snapshot.
   - Offers: hub sidecar `*.offers.json` (offer id + PO price + ETA/closing, including qty 0 listing offers), then PDP enrich only for SKUs still missing offer rows.
3. Merged CSV import (missing rows get `dropped_at` after 3-day grace).
4. **On every refresh:** `PlamodPreorderImageService::cleanupStaleUnlinkedImages()` deletes image files for SKUs dropped ≥ 15 days ago that are **not** linked to a non-archived product.
5. Pending images enqueue `DownloadPlamodPreorderImageJob` per SKU; UI polls sync status every 3s and reloads the table (images appear progressively).

### Live sync progress (`GET /api/v1/preorders/sync-status`)

While status is `queued` or `running`, `counts_json.phase` drives the amber progress panel on `/preorders` (polled every 3s):

| Phase | UI label |
| --- | --- |
| `discover` | Discovering manufacturer filters… |
| `hub_export` | Exporting kits hub (New Preorders + Offer Sheets)… |
| `manufacturer_merged` / `export` | Exporting Bandai preorders (`processed/total`) + current filter + `rows of ~expected` |
| `pdp_enrich` | Enriching preorder details from PDPs |
| `import` | Merging and importing rows… |
| `images` | Downloading images (`done/total`) with progress bar |

While the merged export is running, `GET /api/v1/preorders/sync-status` also merges `plamod/preorder_export_progress.json` (same pattern as restock in-stock progress).

## Table layout

- **Open** checkbox (disabled when a store offer is already open).
- **Image** thumbnail (~80×80px).
- **Product** column stacks name (primary), SKU/barcode + New / Store open / Store closed badges (secondary), series · manufacturer (tertiary)—same pattern as Products grid.

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
- **Serial job chain (no parallel Plamod hits):** `SyncPlamodPreordersJob` → `Bus::chain` on queue **`plamod_sync`**: hub kits export → merge/import/offers/images finalize. Image downloads still queue separately on `default`.
- Each failed step appends a JSON line to `storage/app/private/plamod/preorder_sync_logs/sync-{id}-failures.jsonl` and emits a Laravel warning log.
- Sync status `counts` includes `manufacturer_export_succeeded`, `manufacturer_export_failed`, `manufacturer_export_retried`, `failure_summary` (grouped by error kind), and `failure_log_path`. The Preorders page shows a summary panel when manufacturer exports partially fail.

## Crawl coverage (Refresh from Plamod)

Each refresh merges:

1. **Hub snapshot** — `/retailer/preorders` with **Plastic Model Kits** and **Figures** checked in turn; **New Preorders** + **Offer Sheets**. CSV, then JSON, then cards.
2. **Offers** — hub sidecar offer id / PO price / dates into `plamod_preorder_offers`. Restock committed qty still counts only rows with qty &gt; 0.
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
- Hub refresh imports **Figures** as well as kits. `Figures` / `Figure` stay off the exclude list so that category can appear on the pick list; finer figure lines the operator already hid (Prize Figure, Nendoroid, Scale Figure, etc.) stay excluded until they un-hide them.
- Server-side filter on index + search-lines snapshot step (not per-user).
- Index `category_facets` lists every active category with a kit count for the current live-window / leftovers / store-offer / new-only scope (exclude list does not hide facet rows).
- Table Category filter inspects those kits even if the category is already excluded. **Hide this category** appends the current filter to the exclude list, then jumps to the next live category.

## Bandai manufacturer series filters

- Table: `plamod_preorder_manufacturer_filters` — one row per discovered Plamod sidebar **series** or SD **category line** (Tier 2b) for manufacturer id `1` (BANDAI HOBBY).
- Decision: `undecided` (default for new discoveries), `include`, or `exclude`. Only **include** rows are scraped on refresh; decisions can be changed anytime.
- **Refresh series list** — `POST /api/v1/preorders/manufacturer-filters/discover` queues a background job (returns `job_id` immediately); SPA polls the same endpoint with `{ job_id }` every 3s to avoid Cloudflare HTTP 524 on long Playwright scrapes. On completion, upserts counts; bootstrap defaults tier Gundam / 30MM / Pokémon / etc. to **include** and known noise IPs to **exclude**.
- UI: three columns on Preorders settings panel — Not decided, Included, Excluded — with action buttons per row.

## Schedule

- Daily **`plamod:preorders-sync`** at 06:00 America/Toronto (after in-stock restock at 05:00). Same path as **Refresh from Plamod**. Skips if a refresh is already queued or running. Manual run still available on `/preorders` or `php artisan plamod:preorders-sync`.

## Ops note

After changing `node/plamod-scraper/**`, restart the scraper container so the running Node process picks up new routes:

`docker restart pricing-tool-plamod-scraper`

## Related backend

- Services: `app/Services/Plamod/PlamodPreorder*`
- Jobs: `app/Jobs/Plamod/SyncPlamodPreordersJob`, `ExportPlamodPreorderHubCsvJob`, `ExportPlamodManufacturerFilterJob`, `RecoverFailedPlamodManufacturerFiltersJob`, `FinalizePlamodPreorderSyncJob`, `DownloadPlamodPreorderImageJob`
- Tables: `plamod_preorders`, `plamod_preorder_sync_logs`, `plamod_preorder_manufacturer_filters`
