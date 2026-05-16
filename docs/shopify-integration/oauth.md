# Shopify OAuth (Dev Dashboard credentials)

OAuth uses **authorization code grant** (`SHOPIFY_CLIENT_ID` + `SHOPIFY_CLIENT_SECRET`) and stores offline Admin API tokens encrypted in **`shopify_oauth_installations`**.

## Bootstrap

1. In Dev Dashboard configure **Allowed redirection URL(s)** to match `SHOPIFY_OAUTH_REDIRECT_URI` or the default `{APP_URL}/shopify/oauth/callback`.
2. `.env`:
   - `SHOPIFY_STORE_DOMAIN` — exact `{shop}.myshopify.com` this ERP instance binds to.
   - `SHOPIFY_CLIENT_ID`, `SHOPIFY_CLIENT_SECRET`
   - `SHOPIFY_OAUTH_SCOPES` — comma-separated Admin scopes aligned with ERP sync/read scope.
   - `SHOPIFY_WEBHOOK_SECRET` — webhook signing secret (not interchangeable with OAuth client_secret).
3. With an active Laravel web session open a browser to `GET /shopify/oauth/install` (CLI hint: `php artisan shopify:oauth:url`).
4. Approve on Shopify; callback hits `/shopify/oauth/callback`. Token persists; Artisan sync (`shopify:sync`) and webhook/GraphQL code read it from DB.

Embedded admin apps may initially load behind `embedded=1`. If OAuth redirects are blocked by iframe rules, finish install **top-level** in a new tab targeting `/shopify/oauth/install`.

## Tunnel password gate compatibility

External access middleware bypasses **`GET /shopify/oauth/callback`**, so Shopify can redirect the merchant browser straight to OAuth completion without visiting `/external-login` first (`hmac`, `timestamp`, etc. authenticate the Shopify leg).