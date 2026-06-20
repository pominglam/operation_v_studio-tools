# Phase 9 — Nav cutover (operations)

## Status (2026-06-20)

Phase 9 **complete** on production storefront. Rollback: `storage/app/private/shopify/nav-rollback/main-menu-20260620_115924.json`

## Command

```powershell
docker exec pricing-tool-php php artisan shopify:storefront-nav-cutover
```

Options:

| Flag | Purpose |
| --- | --- |
| `--export-only` | Export GraphQL `main-menu` rollback JSON only |
| `--skip-collections` | Skip collection upsert/publish |
| `--skip-menu` | Skip menu export/update (collections only) |
| `--dry-run` | Print planned actions |

Rollback files: `storage/app/private/shopify/nav-rollback/main-menu-*.json`

## OAuth scopes required (2026-05-25)

Add to `SHOPIFY_OAUTH_SCOPES` and re-install:

- `read_online_store_navigation`
- `write_online_store_navigation`

Re-install URL (from ERP host):

```text
GET {APP_URL}/shopify/oauth/install
```

CLI: `php artisan shopify:oauth:url`

## Target `main-menu` tree (§2)

**Tools & Supplies** replaces **Paints & Markers** + **Tools**. **Model kits** and **Miscellaneous** unchanged.

Children (in order):

1. All tools & supplies → `/collections/tools-and-supplies`
2. Brushes → `/collections/brushes`
3. Drills & bits → `/collections/drills`
4. Tweezers → `/collections/tweezers`
5. Scribing tools → `/collections/scribing-tools`
6. Adhesives → `/collections/adhesives`
7. Nippers & knives → `/collections/nippers-and-knives`
8. Sanding → `/collections/sanding`
9. Tapes → `/collections/tapes`
10. Markers → `/collections/markers`
11. Paints → `/collections/paints`
12. Decals → `/collections/decals`
13. Airbrush → `/collections/airbrush`
14. Other tools & supplies → `/collections/workshop-misc`

## Rollback

Restore from latest `storage/app/private/shopify/nav-rollback/main-menu-*.json` via Shopify Admin → Navigation, or re-run a future restore command.

Storefront scrape reference (pre-cutover): `main-menu-storefront-scrape-20260525.json`

## Deferred

- **Phase 9.4** Getting started top-level nav — tracked in `.cursor/task-tracker.json` as `storefront_getting_started_nav`
