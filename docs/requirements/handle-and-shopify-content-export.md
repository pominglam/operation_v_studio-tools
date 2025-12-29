## Handle persistence + Shopify content CSV export (images + description)

### Goal

- Store Shopify **Handle (slug)** in `products.handle`.
- Add a new export: **Shopify content CSV** that includes:
  - **Body (HTML)** from ingested content
  - **Image Src** rows for product images using signed, temporary public URLs (via Cloudflare quick tunnel)
- Keep the existing Shopify export **unchanged**.

### Data model

- `products.handle` (nullable string)
  - This is the canonical handle used for Shopify updates/exports.
  - **Stability rule**: if `products.handle` is already set, it is not regenerated.
  - If missing, it is generated once during “prepare” and persisted.

### Import handles (Shopify CSV)

UI: **Products → Import → Import handles (Shopify)**

- Input CSV columns required:
  - `Variant SKU`
  - `Handle`
- Matching:
  - `Variant SKU` → `products.sku`
- Behavior:
  - Always overwrites `products.handle` for matched SKUs with a non-empty Handle
  - Blank Handle rows: skipped
- Report returned in UI:
  - `missing_in_system`: SKUs present in CSV but missing in our DB
  - `missing_sku_rows`: count of rows with blank SKU
  - `missing_handle_rows`: count of rows with blank Handle

### Images-only public exposure (Cloudflare quick tunnel)

We expose **only product image URLs** to Shopify (not the whole web app):

- Images route: `GET /shopify-images/{assetId}?expires=...&signature=...`
  - URLs are **signed** and **expire** (currently ~72 hours)
- A dedicated “images-only” HTTP entrypoint runs with:
  - `SHOPIFY_IMAGES_ONLY=true`
  - A global middleware blocks all non-`/shopify-images/*` paths (returns 404)
- Cloudflare quick tunnel (`trycloudflare.com`) points to that images-only entrypoint.

UI: **Products → Export → Shopify content export (images + description)**

- “Start / Update tunnel” / “Stop tunnel”
- Shows current `trycloudflare.com` URL when running

### Shopify content CSV export (new)

UI: **Products → Export → Shopify content export (images + description)**

Two-step flow:

1. **Prepare Shopify content CSV**
   - Calls `POST /api/v1/products/exports/shopify-content/prepare`
   - Returns:
     - `download_url`
     - counts (exported products/rows)
     - skipped lists (missing handle / duplicate handle)
2. **Download CSV**
   - Browser downloads from `download_url`

#### Body (HTML) precedence

For each product:

1. HLJ
2. Competitor sites (most recent captured during price research runs)
3. Plamod
4. Empty

#### Image rows

- One CSV row per image.
- First image is placed on the primary product row.
- Additional images are exported as extra rows with only Handle + Image fields.


