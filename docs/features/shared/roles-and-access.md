# Roles & access control (Web + API)

**Primary code:** `app/Http/Middleware/ExternalAccessPasswordMiddleware.php`, `resources/js/lib/accessRole.ts`, `resources/js/router.ts`.

## How the SPA learns the role

`accessRole.ts` reads `<meta name="external-access-role" content="...">` from `app.blade.php`.

- Unrecognized / empty → treated as **`admin`** (full app).
- **`employee`** → router guard + restricted API allow-list (below).

## Employee router guard

`router.beforeEach`:

- Allow only **`/employee/inventory-count`**.
- Any other path → forced redirect back to **`/employee/inventory-count`**.

So employees **cannot** open `/products` or other routes by URL alone, even if HTML loaded.

## External access middleware (server)

When **not** in `shopify_images_only` mode:

1. **`POST /api/webhooks/shopify`** → **always bypasses cookie/password gate** (**HMAC** validation + logging only).
2. **Loopback host** (`localhost`, `127.0.0.0/8`, `[::1]`, `::1`, optional port) → **no password**; role attribute defaults to **admin** for that request.
3. Otherwise, if external access is **disabled** or password **not configured** → **404** (hidden deployment).
4. Valid cookie → request proceeds with **`external_access_role`** resolved from cookie payload.
5. **Employee cookie** → web paths restricted; non-allowed paths → **404**. API paths restricted to allow-list → other APIs → **404** JSON `{ ok:false, error:"not_found" }`.
6. **Missing cookie + API** (except `POST /api/webhooks/shopify`) → **`401`** JSON `{ ok:false, error:"external_auth_required" }`.
7. **Missing cookie + browser** → redirect **`/external-login?next=...`**.

### Employee API allow-list

Permitted endpoints include:

- **`/api/v1/inventory-check/employee/**`** (session lifecycle, scan, line CRUD).
- **`GET /api/v1/product-assets/{id}/view`** — inline images on scan cards.

Everything else remains blocked for the employee role unless loopback bypass applies.

## Related UI rules

- **Confirm before destructive UI actions** aligns with **`confirm-delete-actions`** rule (inventory check delete, maintenance flush/restore, etc.). See individual screen docs.
