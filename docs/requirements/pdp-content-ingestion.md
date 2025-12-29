# Products — PDP Content Ingestion (Plamod images + HLJ description) (Requirements)

## Goal
Allow admins to ingest product content into our system so it can later be exported to Shopify:

- Download and store external product assets (images, etc.)
- Store a product description and basic PDP attributes
- Provide a UI to preview the ingested PDP content
- Provide a bulk “sync missing PDP info” flow with progress tracking and cancel

## Data model

### External content
`product_external_contents`:
- `product_id`
- `source` (e.g. `plamod`, `hlj`)
- `title` (nullable)
- `description_html` (nullable)
- `attributes_json` (nullable JSON)

### External assets
`product_external_assets`:
- `product_id`
- `source` (e.g. `plamod`)
- `kind` (e.g. `image`)
- `storage_path` (private storage path)
- `filename`
- `mime_type`, `size_bytes`, `checksum_sha256`

## Sources and precedence
- The UI should prefer `hlj` description when present (because Plamod descriptions can be generic).
- Assets are sourced from Plamod ZIP downloads.

## API (v1)

### Trigger “Get product info” for a product
`POST /api/v1/products/{id}/product-info/sync`

Behavior:
- Best-effort: downloads/extracts Plamod assets (images) and persists content/assets.
- Best-effort: fills missing description from HLJ (barcode → SKU → product name search).
- The manual “Get product info” action attempts Plamod assets even when `product.vendor` is not `Plamod`.

### Read ingested PDP info for a product
`GET /api/v1/products/{id}/plamod`

Returns:
- Selected `content` (with source preference)
- `assets[]` including `download_url` and `view_url`

### View/download external assets
- `GET /api/v1/product-assets/{id}/view` (inline)
- `GET /api/v1/product-assets/{id}/download` (attachment)

### Bulk sync (missing PDP info)
`POST /api/v1/products/sync-missing-info`

Behavior:
- Queues jobs using a Laravel **job batch**
- Returns `batch_id`

### Job batch status + cancel
- `GET /api/v1/job-batches` (recent batches)
- `GET /api/v1/job-batches/{id}` (status/progress)
- `POST /api/v1/job-batches/{id}/cancel` (stop a running batch)

## UI (Products page)
- An “Info” cell opens a right-side drawer showing:
  - Admin controls (**Get product info** button, status)
  - PDP preview (carousel, title, SKU, **selling price**, description, attributes)
- A “Missing info” filter supports:
  - `pdp_images`, `pdp_description`, `barcode`, `selling_price`, and `ok` (complete)
- A “Sync missing PDP info” button queues a batch for the currently-filtered set (excluding `ok`).

## Tests
- API + schema tests exist for batch status/cancel and external content/assets persistence.
- UI tests cover key components (e.g., nav/filters/state persistence) where applicable.


