# Shopify Export & Inventory Import (Requirements)

## Goal
Support Shopify-related workflows while keeping internal data authoritative:

- Export products in Shopify CSV format
- Track whether a product is published on Shopify
- Track available inventory quantity for sale
- Import inventory quantities from a Shopify export CSV

## Shopify export rules

### Variant Price
- If `selling_price` exists: export it as **Variant Price**
- If `selling_price` does not exist: **do not export** that product and show it in the web UI as excluded

### Published
- Shopify CSV `Published` column must reflect `products.published_on_shopify`:
  - true → `TRUE`
  - false → `FALSE`

### Missing barcode export
- Provide a way to export products missing barcodes as a CSV (operational workflow).

### Export current inventory (barcoded products export)
Provide a way to export **all products with barcodes** as a CSV for operational inventory review.

- Sorted by: `vendor` (asc), then `type` (asc), then `sku` (asc)
- Columns:
  - `vendor`
  - `sku`
  - `type`
  - `product name` (internal `products.description`)
  - `available amount` (`products.available_qty`)
  - `selling price` (latest `product_selling_prices.selling_price`)

## Inventory tracking

### Data model
`products.available_qty`:
- Nullable integer
- Represents “available to sell” inventory
- Must not be negative (UI should prevent saving negatives)

### Import inventory from Shopify CSV
Input CSV columns used:
- `Variant SKU` → matches internal `products.sku`
- `Variant Inventory Qty` → imported into `products.available_qty`

Behavior:
- The UI must show which rows were **not updated** because the SKU does not exist in our system.
- System must create a safety backup before applying the import.

## UI requirements
- Products table includes “Published” and “Available” columns
- Bulk update dialog includes “Published on Shopify”

## Tests
- Export tests verify `Published` column behavior.
- Schema tests verify `available_qty` and `published_on_shopify` columns exist.
- Import tests verify:
  - correct column mapping
  - updates applied
  - missing SKUs are reported


