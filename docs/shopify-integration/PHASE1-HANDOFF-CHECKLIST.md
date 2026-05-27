# Phase 1 — Shopify ERP integration handoff checklist

**ERP host:** `https://ovs.centredentairevsl.com`  
**Webhook URL (HTTPS POST):** `https://ovs.centredentairevsl.com/api/webhooks/shopify`  
**Companion docs:** [`README.md`](./README.md), [`STEP1-architecture-conflict-report.md`](./STEP1-architecture-conflict-report.md)

---

## 1. Files changed / created

### Laravel app (ERP Admin GraphQL module — Phase 1)

| Area | Paths |
| --- | --- |
| **Migration** | `database/migrations/2026_05_13_120000_shopify_integration_foundation_tables.php` |
| **Config** | `config/shopify.php` |
| **HTTP** | `routes/api.php` (webhook route before `v1`) · `app/Http/Controllers/Api/Webhooks/ShopifyWebhookController.php` |
| **Middleware** | `app/Http/Middleware/ExternalAccessPasswordMiddleware.php` · `app/Http/Middleware/ShopifyImagesOnlyMiddleware.php` |
| **Contracts / exceptions** | `app/Contracts/Shopify/ShopifyAdminGraphQlClientInterface.php` · `app/Exceptions/Shopify/ShopifyAdminConfigurationException.php` · `app/Exceptions/Shopify/ShopifyGraphQlException.php` |
| **GraphQL client** | `app/Services/Shopify/Admin/ShopifyAdminGraphQlClient.php` |
| **Queries** | `app/Services/Shopify/Admin/GraphQl/ShopifyAdminGraphQlQueries.php` |
| **Support** | `app/Services/Shopify/Admin/Support/ShopifyGraphQlNodeParser.php` |
| **Sync** | `app/Services/Shopify/Admin/Sync/ShopifySyncMetrics.php` · `ShopifySyncRunnerInterface.php` · `ShopifyLocationSyncRunner.php` · `ShopifyProductCatalogSyncRunner.php` · `ShopifyInventoryLevelSyncRunner.php` · `ShopifyOrderSyncRunner.php` · `ShopifyCustomerSyncRunner.php` · `ShopifyCollectionSyncRunner.php` · `ShopifyErpSyncCoordinator.php` |
| **Webhooks** | `app/Services/Shopify/Admin/Webhooks/ShopifyWebhookIngressDto.php` · `ShopifyWebhookIngressService.php` · `ShopifyWebhookIngressResult.php` |
| **Events / listeners** | `app/Events/Shopify/ShopifyWebhookReceived.php` · `app/Listeners/RecordShopifyWebhookDispatch.php` |
| **Console** | `app/Console/Commands/ShopifySyncCommand.php` |
| **Provider** | `app/Providers/ShopifyErpServiceProvider.php` · `bootstrap/providers.php` |
| **Models** | `app/Models/Shopify/` (`ShopifySyncLog`, `ShopifyWebhookLog`, `ShopifyLocation`, `ShopifyProduct`, `ShopifyProductVariant`, `ShopifyInventoryItem`, `ShopifyInventoryLevel`, `ShopifyOrder`, `ShopifyCustomer`, `ShopifyCollection`) |

### Cross-cutting configuration & docs

| Area | Paths |
| --- | --- |
| **Logging** | `config/logging.php` (`shopify` daily channel → `storage/logs/shopify.log`) |
| **Env template** | `.env.example` (Shopify + optional tuning vars) |
| **Feature / integration docs** | `docs/shopify-integration/README.md` · `STEP1-architecture-conflict-report.md` · **this file** |
| **Feature catalog** | `docs/features/backend/system-catalog-services-and-http.md` · `docs/features/shared/roles-and-access.md` |
| **Cursor rules** | `.cursor/rules/shopify-erp-integration.mdc` (+ any prior README cross-references already in `.cursor/rules/`) |

### Theme mirror (foundation only)

| Path | Purpose |
| --- | --- |
| `themes/shopify-draft/.gitkeep` | Empty draft workspace placeholder (no automated theme publish). |

### Tests & fakes

| Path | Purpose |
| --- | --- |
| `tests/Feature/Shopify/ShopifyWebhookIngressTest.php` · `ShopifyErpSyncCoordinatorTest.php` · `tests/Feature/ShopifyImagesOnlyTest.php` (extended) | Automated coverage |
| `tests/Fakes/FakeShopifyAdminGraphQlClient.php` | Offline GraphQL shim for coordinator tests |

> **Note:** Pre-existing Shopify-adjacent code (image tunnel, CSV exports, `ShopifyImagesOnlyMiddleware`) remains separate from **`App\Services\Shopify\Admin\**`** (ERP Admin API).

---

## 2. What is verified

| Item | Evidence |
| --- | --- |
| **Migration applies** | `php artisan migrate` succeeds (SQLite override used under Docker CI-style run; includes `shopify_*` tables). |
| **Pest Feature tests** | `php artisan test tests/Feature/Shopify tests/Feature/ShopifyImagesOnlyTest.php` — **9 tests** (webhooks, coordinator + inventory path, images-only + webhook exemption). |
| **Pint (Shopify paths)** | `php vendor/bin/pint` applied to Shopify-related dirs; **`pint --test`** clean on those paths. |
| **Static wiring** | Webhook **`POST /api/webhooks/shopify`** registered **before** `v1`; middleware bypass documented; **`ShopifyErpServiceProvider`** registered in `bootstrap/providers.php`. |

---

## 3. What is **not** verified

| Gap | Meaning |
| --- | --- |
| **Live Shopify Admin API** | No real store token run in CI/agent; GraphQL queries not exercised against production Shopify responses (field deprecations, throttling). |
| **Production TLS + reverse proxy → raw webhook body** | HMAC validated on Laravel’s **`$request->getContent()`**; proxy must preserve body byte-for-byte. |
| **MySQL parity in agent** | Local migrate smoke used **SQLite + env override** where Docker lacked `pdo_mysql`; **production uses MySQL 8.4** per project rules — run migrate on prod-like DB separately. |
| **PHPStan** | **`vendor/bin/phpstan` not present** — no static-analysis gate run for this change set (see §9). |
| **Full-app Pint `--dirty`** | Docker `php:8.4-cli` image has **no `git`**; `--dirty` may show **0 files** unless run on a machine with Git + unstaged commits. |

---

## 4. Required Shopify **custom app** scopes (Phase 1 read-only)

Configure these Admin API scopes on the custom app (read-only ERP sync):

- `read_products`
- `read_inventory`
- `read_locations`
- `read_orders`
- **`read_all_orders`** — required with `read_orders` or `write_orders` to backfill orders older than ~60 days (Shopify platform limit)
- `read_customers`
- `read_content`
- `read_themes`

**Phase 2 (do not enable for Phase 1 unless policy allows):**

- `write_products`, `write_inventory`, `write_orders`, `write_customers`, `write_content`, `write_themes`

---

## 5. Exact `.env` values to fill

**Required:**

| Variable | Example / notes |
| --- | --- |
| `SHOPIFY_STORE_DOMAIN` | Your shop host, e.g. `your-store.myshopify.com` (scheme optional — stripped). Single store per ERP deployment. |
| `SHOPIFY_CLIENT_ID` | Dev Dashboard **Client ID**. |
| `SHOPIFY_CLIENT_SECRET` | Dev Dashboard secret (`client_secret`) — **never** ship to SPA. |
| `SHOPIFY_API_VERSION` | Pinned GraphQL segment, e.g. `2025-10` (see default in `.env.example` / `config/shopify.php`). |
| `SHOPIFY_OAUTH_REDIRECT_URI` | Optional; default `{APP_URL}/shopify/oauth/callback`. Must exactly match Allowed redirection URLs in Dev Dashboard. |
| `SHOPIFY_OAUTH_SCOPES` | Comma-separated scopes (defaults ship in `config/shopify.php` / `.env.example`). |
| `SHOPIFY_WEBHOOK_SECRET` | Webhook signing secret — **distinct** from OAuth `client_secret`. Mandatory for webhook ingress (`X-Shopify-Hmac-Sha256`); missing → HTTP **503**, `verification_error=missing_webhook_secret`. |

**After env is set**, complete **`GET /shopify/oauth/install`** in a browser (session-backed) → Shopify redirects → **`GET /shopify/oauth/callback`** persists an offline Admin token into **`shopify_oauth_installations`** (encrypted). See **`docs/shopify-integration/oauth.md`**.
**Optional (already supported in `config/shopify.php`):**

| Variable | Purpose |
| --- | --- |
| `SHOPIFY_GRAPHQL_TIMEOUT` | Default `120` (seconds). |
| `SHOPIFY_GRAPHQL_PAGE_SIZE` | Clamped **5–250**, default **50**. |
| `SHOPIFY_GRAPHQL_RETRY_ATTEMPTS` | Default **3**. |
| `SHOPIFY_GRAPHQL_RETRY_SLEEP_MS` | Default **250**. |
| `SHOPIFY_THEME_MIRROR_PATH` | Override draft theme mirror path (default `themes/shopify-draft`). |

**Logging (optional):**

| Variable | Purpose |
| --- | --- |
| `SHOPIFY_LOG_LEVEL` | Overrides `shopify` log channel level (when used in stack). |
| `SHOPIFY_LOG_DAYS` | Retention days for **`storage/logs/shopify*.log`** (see `config/logging.php`). |

---

## 6. Webhook URL

Register every subscription URL in Shopify (HTTPS **POST**) as:

### `https://ovs.centredentairevsl.com/api/webhooks/shopify`

Ensure the host terminates TLS correctly and proxies forward the POST body unchanged for HMAC validation.

---

## 7. First **safe** live smoke test command

After `.env` is filled with **live** Shopify credentials (`SHOPIFY_*`), on the ERP server shell:

```bash
php artisan shopify:sync locations
```

**Why this first:** Locations are typically small and validate **token, API version, network, pagination, upserts**, and **`shopify_sync_logs`** writes without touching the larger product/order surfaces.

Expect:

- CLI table with **`status`** (e.g. `completed`), **`records_*`**, **`duration_ms`**
- Rows in **`shopify_locations`** and a row in **`shopify_sync_logs`**

Then optionally:

```bash
php artisan shopify:sync products
```

---

## 8. How to confirm synced locations / products in the DB

**Locations**

```sql
SELECT id, gid, name, graphql_updated_at, updated_at FROM shopify_locations ORDER BY id DESC LIMIT 50;
```

**Products**

```sql
SELECT id, gid, handle, title, status, graphql_updated_at, updated_at FROM shopify_products ORDER BY id DESC LIMIT 50;
```

**Variants linked to products**

```sql
SELECT gid, product_gid, sku, inventory_item_gid, graphql_updated_at
FROM shopify_product_variants ORDER BY id DESC LIMIT 50;
```

**Operational sync audit**

```sql
SELECT id, sync_key, status, records_fetched, records_created, records_updated,
       duration_ms, error_summary, started_at, finished_at
FROM shopify_sync_logs ORDER BY id DESC LIMIT 20;
```

**Tinker alternatives**

```bash
php artisan tinker --execute="echo \\App\Models\\Shopify\\ShopifyLocation::query()->count();"
php artisan tinker --execute="echo \\App\Models\\Shopify\\ShopifyProduct::query()->count();"
```

---

## 9. Known limitations

| Limitation | Detail |
| --- | --- |
| **Product variants capped at 100 per product** | `PRODUCTS_PAGE` uses `variants(first: 100)`. Shops with **>100 variants per SKU set** need a follow-on sync (pagination / split query) — treat as Phase 1.1 or webhook-driven deltas. |
| **PHPStan not installed** | `vendor/bin/phpstan` is absent; **`composer.json` does not declare `phpstan/phpstan`**. Workspace rules may still cite PHPStan — add dev dependency + config when you want a hard gate (not an application runtime bug). |
| **Local PHP / Composer not consistently configured** | Windows PATH may omit `php`/`composer`; **WSL** may ship **PHP 8.4 without `mbstring`** (Laravel `mb_split` failure) while Composer requires **≥ 8.4**. Options: **`sudo apt install php8.4-mbstring`** (WSL), or **Docker `php:8.4-cli`**, **Laragon/Herd**, etc. Verification was done with Docker + SQLite override for migrate in one environment snapshot. |

---

## 10. Recommended next **Phase 1.1** task

**Harden tooling + ergonomics**, then widen read coverage safely:

1. **Install `php8.4-mbstring` on dev (WSL) or pin team dev to Docker/herd PHP 8.4** — removes “works only in Docker” friction.  
2. **Add PHPStan (and optional Larastan) + scoped `paths`/`excludePaths`** beginning with **`app/Services/Shopify/Admin/**`** so the ERP module is statically guarded without boiling the ocean.  
3. **Variants >100:** add a paginated **`productVariants`** (or recursive) sync path keyed by `product_gid`, or document **manual `shopify:sync` segment** ordering + retries for large catalogs — still **read-only**.

---

_End of checklist — archive with release / PR._
