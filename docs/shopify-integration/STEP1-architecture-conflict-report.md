# STEP 1 — Architecture & rules conflict analysis (Shopify ERP Phase 1)

**Project:** Operation V Pricing Tool (`pricing-tool`) bridging toward **Operation V Studio** Shopify integration.  
**Date:** Analysis prior to Phase 1 implementation.

## Summary verdict

Implementation **can proceed safely** provided we:

1. **Bypass external-access middleware** on the Shopify webhook path (documented collision).
2. **Bypass `SHOPIFY_IMAGES_ONLY` gate** for the webhook path (documented collision).
3. Respect **DAL + service layering** (`Shopify\Admin\*` orchestration behind interfaces; repositories optional in Phase 1 where models + focused services suffice—see resolution).
4. Keep **controllers thin** (<40 lines/action) delegating webhook handling to dedicated services.

---

## Conflicts / overlaps with new Shopify rules

| Existing rule/pattern | New requested rule | Conflict / overlap | Risk | Recommended resolution |
| --- | --- | --- | --- | --- |
| `ExternalAccessPasswordMiddleware` gates **all** non-loopback traffic when external mode is enabled; API returns **401/404** without cookie | Shopify webhooks must **POST anonymously** from Shopify IPs with **HMAC** only | **Hard conflict**: webhooks blocked in production gated mode | **High** | Exempt **`/api/webhooks/shopify`** from password gate entirely; rely on HMAC + Shopify IP allowlisting later if desired. Apply same **before** 404-hide logic where possible. Document in Shopify setup. |
| `ShopifyImagesOnlyMiddleware` aborts **404** everywhere except `/shopify-images/*` | Webhooks must reach Laravel on same app host | **Hard conflict** on images-only workers | **High** | Also exempt **`/api/webhooks/shopify`** from images-only middleware. |
| Existing `App\Services\Shopify\*` for **tunnel + signed product images CSV** exports (not Admin API ERP) | New **Admin GraphQL** integration modules | Naming **overlap only** (“Shopify”), different concerns | **Low** | Namespace new code under **`App\Services\Shopify\Admin`** and **`App\Http\Controllers\Api\Integration\Shopify`** (or `\Shopify\Webhook`). Document distinction in Cursor rule + feature docs Phase 2. |
| Existing “Shopify CSV export” workflows from internal `products` table | Shopify **read mirror** tables (`shopify_products`, …) | Conceptual overlap on “products” wording | **Low** | Keep internal catalog `products`; mirror tables prefix **`shopify_`** purely for storefront truth. Phase 2 may link FK `products.shopify_gid` separately. |
| `services.mdc`: **interfaces for external calls** | GraphQL HTTP to Shopify Admin | Compliance | **Low** | Introduce **`ShopifyAdminGraphQlClientInterface`**; concrete client uses Laravel `Http`. |
| `services.mdc`: **≤60 lines per method**, **avoid network in DB transactions** | Full-catalog sync crosses many writes + HTTP | **Friction** if coded as one mega-method | **Medium** | Coordinator service + focused services (`SyncLocations`, …); **`Http`** outside transactions; transactional **per upsert chunk** optional (avoid long locks). Pagination loops in private helpers. |
| `project.mdc` / infra: **`external_api` log channel** for outbound scraping | Dedicated Shopify structured sync logs | Preference overlap | **Low** | Add **`shopify` log channel** + DB tables **`shopify_sync_logs`**, **`shopify_webhook_logs`** per requirements; optionally also log failed GraphQL payloads at `warning/error` sans secrets on `external_api`-style masking. |
| No GraphQL infrastructure today | Shopify requires GraphQL-first | Gap (not clash) | **Low** | No REST Admin client added; Laravel `Http::` POST `graphql.json` only. |

---

## Naming / tables vs conventions

Existing migrations use Laravel-style **snake_case plural** (`products`, `purchase_orders`). Recommended mirror tables **`shopify_*`** plural as proposed—consistent and avoids collision with `products`.

---

## Canonical architecture choice for this codebase

|**Layer**| **Choice**| **Why**|  
|---|---|---|
| HTTP ingress | Dedicated controller + thin Form Request (`Json` webhook body raw) | Matches `controllers.mdc`; keeps HMAC parsing out of middleware |
| Shopify HTTP | **`ShopifyAdminGraphQlClient`** (+ interface) using `Illuminate\Support\Facades\Http::retry(...)` | Meets retries; keeps vendor calls mockable |
| Persistence | **Eloquent models** under `App\Models\Shopify` + dedicated sync saver classes splitting upsert logic | Matches existing Eloquent-first project; gradual repo extraction Phase 2 if rows explode |
| Webhooks | `ShopifyWebhookService` persists log row, verifies HMAC, `Event::dispatch` | Matches “dispatch internally, no Phase 2 business writes” |

---

## Phase 2 guardrails encoded

- Commands / Cursor rule explicitly forbid **Admin write mutations** without separate approval/design.
- Listener skeleton logs only; mutations attach later gated by policy/feature flags.

---

## Proceed?

**YES** — with middleware exemptions + namespace separation documented above.

