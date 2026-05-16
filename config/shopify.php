<?php

declare(strict_types=1);

return [

    'store_domain' => env('SHOPIFY_STORE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Dev Dashboard OAuth (authorization code grant, offline persistable tokens)
    |--------------------------------------------------------------------------
    |
    | Admin GraphQL resolves access tokens persisted in shopify_oauth_installations.
    |
    */

    'client_id' => env('SHOPIFY_CLIENT_ID'),

    'client_secret' => env('SHOPIFY_CLIENT_SECRET'),

    /*
    | Full URL listed under Dev Dashboard Allowed redirection URLs. Defaults to `{APP_URL}/shopify/oauth/callback`.
    */

    'oauth_redirect_uri' => env('SHOPIFY_OAUTH_REDIRECT_URI'),

    /*
    | Comma-separated Admin API scopes granted at OAuth time.
    */

    'oauth_scopes' => env(
        'SHOPIFY_OAUTH_SCOPES',
        'read_locations,read_products,read_inventory,read_orders,read_customers,read_collections',
    ),

    /*
    |--------------------------------------------------------------------------
    | Admin GraphQL API version string (pinned)
    |--------------------------------------------------------------------------
    |
    | Example: "2025-10", "2026-01". Must match Shopify Admin API versioning strategy.
    |
    */

    'api_version' => env('SHOPIFY_API_VERSION', '2025-10'),

    /*
    |--------------------------------------------------------------------------
    | Shopify webhook verification secret (distinct from webhook transport)
    |--------------------------------------------------------------------------
    |
    */

    'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Operational tuning
    |--------------------------------------------------------------------------
    */

    'graphql_timeout_seconds' => (int) env('SHOPIFY_GRAPHQL_TIMEOUT', 120),

    'graphql_page_size' => max(5, min(250, (int) env('SHOPIFY_GRAPHQL_PAGE_SIZE', 50))),

    'graphql_retry_attempts' => max(1, min(8, (int) env('SHOPIFY_GRAPHQL_RETRY_ATTEMPTS', 3))),

    'graphql_retry_sleep_ms' => max(50, min(5000, (int) env('SHOPIFY_GRAPHQL_RETRY_SLEEP_MS', 250))),

    /*
    |--------------------------------------------------------------------------
    | Draft theme workspace (git-backed; never publish from code in Phase 1)
    |--------------------------------------------------------------------------
    |
    | Local folder under project root mirrored for Cursor-assisted Liquid edits.
    |
    */

    'theme_mirror_path' => env('SHOPIFY_THEME_MIRROR_PATH', base_path('themes/shopify-draft')),

];
