# Shopify ERP integration (Operation V Studio)

**Phase 1 close-out:** [`PHASE1-HANDOFF-CHECKLIST.md`](./PHASE1-HANDOFF-CHECKLIST.md) — scopes, `.env`, webhook URL, smoke test, DB checks, limitations.

Companion to [STEP 1 conflict report](./STEP1-architecture-conflict-report.md).

## Scope

- **Phase 1**: Read/sync from Shopify Admin **GraphQL** into local ERP tables, webhook receive + dispatch shell, structured logging.
- **Phase 2**: Mutations from ERP (inventory, publishing, writes) gated behind explicit approvals—do **not** add write calls inside Phase 1 runners.

## Environment

Set in `.env` (examples in `.env.example`):

| Variable | Purpose |
| --- | --- |
| `SHOPIFY_STORE_DOMAIN` | `.myshopify.com` hostname (scheme optional — stripped). Single-tenant binding for persisted tokens. |
| `SHOPIFY_CLIENT_ID` | Dev Dashboard **Client ID** (OAuth authorize + token exchange). |
| `SHOPIFY_CLIENT_SECRET` | Dev Dashboard **secret** (`client_secret`) — backend only — never SPA. |
| `SHOPIFY_API_VERSION` | Admin GraphQL segment, e.g. `2025-10`. Default in `config/shopify.php`. |
| `SHOPIFY_OAUTH_REDIRECT_URI` | Optional full callback URL; defaults to `{APP_URL}/shopify/oauth/callback`. Must match Dev Dashboard **Allowed redirection URL(s)**. |
| `SHOPIFY_OAUTH_SCOPES` | Comma-separated Admin scopes requested during install (see default in `config/shopify.php`). |
| `SHOPIFY_WEBHOOK_SECRET` | Webhook signing secret (`X-Shopify-Hmac-Sha256` on `POST /api/webhooks/shopify`). **Not** interchangeable with OAuth `client_secret`; missing → **503** + `shopify` logs. |

After OAuth succeeds, plaintext Admin tokens are stored encrypted as **`shopify_oauth_installations.access_token`** (via `APP_KEY`). Walkthrough **[`oauth.md`](./oauth.md)**.

Optional: `SHOPIFY_THEME_MIRROR_PATH` — defaults to `themes/shopify-draft` (draft-only; never auto-publish).

## Shopify app scopes

**Phase 1 (read)**

- `read_products`, `read_inventory`, `read_locations`, `read_orders`, `read_customers`, `read_content`, `read_themes`

**Phase 2 (prepare but do not use yet)**

- `write_products`, `write_inventory`, `write_orders`, `write_customers`, `write_content`, `write_themes`

### Production ERP URLs (Operation V Studio)

| Purpose | URL |
| --- | --- |
| ERP web app | **`https://ovs.centredentairevsl.com`** |
| Shopify Admin API webhooks (HTTPS **POST**) | **`https://ovs.centredentairevsl.com/api/webhooks/shopify`** |

Register each subscription in the Shopify app toward that webhook URL once the host is reachable publicly. Use **TLS** end-to-end so the raw body Shopify signs matches what Laravel reads.

## Local commands

- `php artisan shopify:sync {target}` where `target` is `full` or `locations|products|inventory_levels|orders|customers|collections`.
- Inspect `shopify_sync_logs` rows for durations, totals, failures.

## Persistence

Tables from migration `2026_05_13_120000_shopify_integration_foundation_tables.php`:

- Entities: products, variants, inventory items & levels (unique per inventory item × location), locations, orders, customers, collections.
- Ops logs: **`shopify_sync_logs`**, **`shopify_webhook_logs`**.

## Theme workflow (foundation)

Mirror directory: `themes/shopify-draft/` (tracked with `.gitkeep`). Run theme CLI pull/push separately; ERP code does **not** publish themes automatically.

## Architecture notes / Phase 2 extension

### Known Phase 1 limits

- **`products`** query asks for **`variants(first: 100)`** per product — shops with larger variant sets need a follow-on paginated variants sync (planned for Phase 2 / incremental webhook-driven updates).

| Area | Extend by |
| --- | --- |
| GraphQL mutations | Dedicated **write** commands/services under **`App/Services/Shopify/Admin`**, guarded by approvals + explicit scope checks. |
| Webhooks | Listeners keyed off `ShopifyWebhookReceived::$log->topic` (Phase 2). |
| Queues | Long syncs → chunk jobs + checkpoints in `sync_logs` / Redis (optional refactor). |

## Tests

See `tests/Feature/Shopify/*` (`ShopifyWebhookIngressTest.php`, `ShopifyErpSyncCoordinatorTest.php`).

## Local verification (CI parity)

From the project root (PHP 8.4 + Composer on `PATH`):

| Command | Expected |
| --- | --- |
| `php artisan migrate` | **`INFO Nothing to migrate.`** or **`Migration table created`**, then batches **DONE**; exit **0**. |
| `php artisan test tests/Feature/Shopify tests/Feature/ShopifyImagesOnlyTest.php` | Green tests; exit **0**. |
| `php vendor/bin/pint --dirty` | **`✓` fixes** or no changes; exit **0**. |
| `php vendor/bin/phpstan analyse --memory-limit=1G` | See note below. |

**PHPStan:** This repository’s `composer.json` does **not** currently require `phpstan/phpstan` directly, so `vendor/bin/phpstan` may be missing after `composer install`. If the command fails with “not recognized” or file not found, add a dev dependency (e.g. `composer require --dev phpstan/phpstan` and a `phpstan.neon(.dist)` tuned to this app) before expecting that check to pass.

**If `migrate` fails:** Check `DB_*` in `.env` (SQLite file must exist for default example) and MySQL user permissions for production.

**If tests fail:** Ensure `php artisan config:clear`, run `composer dump-autoload`, and that `database/database.sqlite` exists when using the default `.env.example` SQLite DSN.

**If Pint fails:** Run `php vendor/bin/pint` without `--dirty` to format the whole codebase, then retry.
