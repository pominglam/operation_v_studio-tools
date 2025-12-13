# Price Research — Crawler Reference

This document describes how each competitor “crawler” (provider) works:

- Which **search URL** we call
- How we find a **Product Detail Page (PDP)** URL
- How we extract **price**, **original price**, and **availability**
- Known limitations

## Shared parsing rules (all HTML-based providers)

- **PDP-only scraping**: We only trust prices found on a PDP; search/category pages often contain unrelated prices.
- **Price extraction order**:
  1. **JSON-LD** (`application/ld+json`) Product → Offers
  2. **Fallback (CAD-labeled)**: `"$XX.XX CAD"` / `"$XX.XX C$"`
  3. **Fallback (any $)**: first `"$XX.XX"` found
  4. **Sale inference**: only if the page indicates compare-at/list price (`<del>`, `<s>`, `line-through`, “compare at”).
- **Availability**:
  - JSON-LD `availability` mapping:
    - `InStock` → `in_stock`
    - `OutOfStock` → `sold_out`
  - Fallback text scan:
    - “Sold Out” → `sold_out`
    - “Add to cart” → `in_stock`

## Providers

### Gundam Hangar (`gundam_hangar`)

- **Approach**: Uses Gundam Hangar’s public JSON catalog API (preferred over HTML scraping).
- **Search**: API query by description (preferred) / SKU / barcode.
- **PDP**: Constructed from returned `slug` as `/canadian-gundam-store/product/{slug}`.
- **Price**:
  - `final_price` (when > 0) is treated as current price
  - `price` is treated as original price when `final_price` is present
- **Availability**:
  - `stock > 0` → `in_stock`
  - otherwise → `sold_out`

### Panda Hobby (`panda_hobby`)

- **Approach**: HTML search → PDP scrape.
- **Search**: Uses the provider’s configured search URL(s) under the site base URL.
- **PDP detection**: Shopify-style `/products/…` or `/product/…` links.

### Canadian Gundam (`canadian_gundam`)

- **Search**: PrestaShop querystring search endpoint:
  - [Canadian Gundam search example](https://www.canadiangundam.com/search?controller=search&orderby=position&orderway=desc&search_query=HG+1%2F144+%2313+Gundam+Astray+Blue+Frame&submit_search=)
- **PDP detection**: product links under `/gundam-model-kits/…`.
- **Extraction**: PDP HTML + JSON-LD (when present).

### Hobby Bee (`hobby_bee`)

- **Search**: Shopify search endpoint (site uses `/a/search?q=...`).
- **PDP detection**: `/products/…` links.
- **Extraction**: PDP HTML + JSON-LD (when present).

### HobbyWholesale (`hobby_wholesale`)

- **Search**: Magento-style route:
  - [HobbyWholesale search example](https://hobbywholesale.com/search/HG+1%2F144+%2313+Gundam+Astray+Blue+Frame)
- **PDP detection**: `*.html` links on `hobbywholesale.com` (excluding non-PDP routes like `/search/`, `/cart/`, `/checkout/`).
- **Extraction**: PDP HTML + JSON-LD (when present).

### Meeplemart (`meeplemart`)

- **Search**: querystring search endpoint:
  - [Meeplemart search example](https://www.meeplemart.com/store/Search.aspx?SearchTerms=HG%201%2F144%20%2313%20Gundam%20Astray%20Blue%20Frame)
- **PDP detection**: `*.aspx` links on `meeplemart.com`, excluding `/store/Search.aspx`.
- **Extraction**: PDP HTML + JSON-LD (when present).

### Hobby Sense (`hobby_sense`)

- **Search**: Shopify search endpoint:
  - [Hobby Sense search example](https://hobbysense.ca/search?q=HG+1%2F144+%2313+Gundam+Astray+Blue+Frame)
- **PDP detection**: `/products/…` links.
- **Extraction**: PDP HTML + JSON-LD (when present).

