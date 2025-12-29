# Inventory Check (Requirements)

## Goal
Support an auditable inventory-count workflow:

- Export a CSV for in-store counting (with **Handle first**).
- Import the counted CSV back to:
  - Update `products.available_qty` from **Quantity in store**
  - For **Stedi** products only: update `products.description` to **English name** *only when different*
  - Persist **Difference** and **Notes** for review later
- Store each import as a historical **inventory check session** (so past counts can be reviewed).

## CSV format

### Export (barcoded products)
Endpoint: `GET /api/v1/products/export/barcoded`

- Encoding: **UTF‑8 with BOM** (Excel-friendly)
- Sort order: `vendor` (asc), then `type` (asc), then `sku` (asc)
- Columns (in order):
  1. `Handle`
  2. `Vendor`
  3. `SKU`
  4. `Type`
  5. `Product Name`
  6. `English name` (blank)
  7. `Available amount` (snapshot of `products.available_qty`)
  8. `Selling price`
  9. `Quantity in store` (blank)
  10. `Difference` (blank)
  11. `Notes` (blank)

### Import (inventory check)
Endpoint: `POST /api/v1/products/import-inventory-check` (multipart form-data)

Matching rules:

- If `Handle` is present: match `products.handle`
- Else fallback to `(SKU, Vendor)` matching `products.sku` + `products.vendor`

Import behavior:

- `Quantity in store` (when present and non-negative) updates `products.available_qty`
- If vendor is **Stedi** and `English name` is present and differs from current `products.description`, update `products.description`
- Always persist CSV row fields into the inventory check session items, including:
  - `Difference`
  - `Notes`
  - match status (`matched` | `unmatched` | `ambiguous`) and a `match_error` when applicable

Import response:

- Returns a summary including:
  - created `inventory_check` id/uuid
  - counts: rows parsed, matched, applied, unmatched, ambiguous
  - unmatched/ambiguous rows (handle/vendor/sku + reason)

## Data model

### Tables
- `inventory_check`
  - `id` (internal BIGINT)
  - `uuid` (public identifier)
  - `name` (nullable)
  - `source` (nullable)
  - `uploaded_file_path` (nullable; stores the original CSV path)
  - `notes` (nullable)
  - timestamps

- `inventory_check_items`
  - `inventory_check_id` (FK → `inventory_check`)
  - `product_id` (FK → `products`, nullable for unmatched/ambiguous)
  - CSV fields: `handle`, `vendor`, `sku`, `type`, `product_name`, `english_name`, `available_amount`, `quantity_in_store`, `difference`, `notes`
  - audit fields: `match_status`, `match_error`, `applied`, `applied_at`
  - timestamps

## UI requirements
Add a dedicated navbar page: **Inventory Check**

- **Create / Import**: upload CSV, show summary and immediate unmatched/ambiguous rows
- **History**: list past inventory checks
- **Details**: view a session’s items with filters (applied/unmatched/ambiguous) and download the uploaded CSV




