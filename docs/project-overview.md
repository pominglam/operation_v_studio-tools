# Project Overview — Operation V Pricing Tool

## Purpose
This application manages a catalog of products and supports:

- Competitor price research across multiple retailers (incl. AliExpress via Playwright)
- Product data quality workflows (missing PDP info, barcode, selling price)
- Operational maintenance tooling (database backups/restores)
- Shopify-oriented workflows (export format, publication status, inventory import)

## Where requirements live
- High-level feature requirements are documented in `docs/requirements/`.
- Crawler/provider behavior is documented in `docs/requirements/price-research-crawlers.md`.

## Key domains (high level)

### Products
- Source of truth for internal SKU, barcode, cost, selling price, available quantity.
- Supports PDP content ingestion from external sources (assets + descriptions).

### Price research
- Runs price quote collection for configured retailers and stores latest quote per product + site.
- Tracks freshness and supports targeted recrawls.

### Maintenance
- Allows creation of database backups with descriptions and restoration from a selected backup.

## Non-goals (for now)
- Automatic publishing to Shopify (we only prepare exports and track fields used by export).
- Fully automated cookie refresh flows for anti-bot sites (where manual browser interaction is required).


