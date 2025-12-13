# Price Research (Competitor Pricing) — Requirements

## Goal
For each `Product`, collect the **current price** from a fixed list of Canadian retailers and store the result with a **last updated timestamp**. If a product cannot be found on a retailer site, store **Not Found**.

Prices are considered **valid for 14 days**. Before any downstream pricing calculations use a stored competitor price, the system must verify the record is **not expired** and **re-run research** when it is.

## Retailers (Phase 1)
- Gundam Hangar
- Panda Hobby
- Canadian Gundam
- Hobby Bee
- HobbyWholesale
- Meeplemart
- Hobby Sense

## Crawler reference
See `docs/requirements/price-research-crawlers.md` for per-site search URLs, PDP URL patterns, and extraction rules.

## Data Model

### `products`
- `price_researched_at` (nullable timestamp): last time we attempted to refresh competitor pricing for this product.

### `product_price_quotes`
Stores the **latest** known result per product + retailer.

- `product_id` (FK -> `products.id`, restrict on delete/update)
- `site_key` (string): stable key like `gundam_hangar`, `meeplemart`
- `site_name` (string): display name
- `status` (string): `found` | `not_found` | `error`
- `availability` (string, nullable): `in_stock` | `sold_out` | null
- `currency` (string, default `CAD`)
- `price` (decimal, nullable)
- `product_url` (text, nullable): retailer product page URL if found
- `error_message` (text, nullable): populated when `status=error`
- `fetched_at` (timestamp): when the price lookup was performed

Uniqueness: `(product_id, site_key)` unique.

## Expiration Rules
- TTL: **14 days** (configurable via `PRICE_RESEARCH_TTL_DAYS`)
- A product’s research is **expired** if:
  - `products.price_researched_at` is null, or
  - `products.price_researched_at < now() - TTL`

## API (v1)

### Run research
`POST /api/v1/price-research/run`

Request body:
- `ids?: string[]` (UUIDs) — optional; if omitted, runs for all products
- `force?: boolean` — when true, refreshes even if within TTL

Response:
- If `QUEUE_CONNECTION=sync` (tests), runs inline and returns:
  - `queued=false`
  - `run_id` (UUID)
  - `data.processed`, `data.refreshed`, `data.skipped_fresh`, `data.quotes_written`
- Otherwise, enqueues a background job and returns **202**:
  - `queued=true`
  - `run_id` (UUID)

### Run status (for UI progress)
- `GET /api/v1/price-research/runs/latest` → `{ data: Run|null }`
- `GET /api/v1/price-research/runs/{id}` → `{ data: Run }`

`Run` includes:
- `status`: `queued|running|completed|failed`
- `total_products`, `processed_products`, `refreshed_products`, `skipped_fresh_products`
- `total_sites`, `processed_sites`, `quotes_written`
- `started_at`, `finished_at`, `error_message`

### List products with latest quotes
`GET /api/v1/price-research/products?per_page=25`

Response includes:
- product identity (`id`, `sku`, `barcode`, `description`)
- `price_researched_at`
- `expired` boolean
- `quotes[]` (one per retailer that has a stored quote)

## Implementation Notes / Assumptions
- Retailer integrations are implemented as **provider classes**. Each provider attempts to find a matching product using a small set of fallback search terms (`sku`, `barcode`, and `description` where available) and returns:
  - `found` with price + URL, or
  - `not_found`, or
  - `error` with message (network/parse failure)
- Outbound calls use timeouts + retries and write structured logs to the `external_api` log channel.


