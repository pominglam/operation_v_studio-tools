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
        'read_locations,read_products,read_inventory,read_orders,read_all_orders,read_customers,read_collections',
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

    /*
    | PO Prepare skips full-store Shopify sync when both products and inventory_levels
    | mirror segments completed within this window (seconds). Default: 1 hour.
    */

    'po_prepare_mirror_freshness_seconds' => max(60, (int) env('SHOPIFY_PO_PREPARE_MIRROR_FRESHNESS_SECONDS', 3600)),

    /*
    | Optional: pin inventory pushes to a specific Shopify location GID. When unset, the first
    | active location with fulfills_online_orders is used (see ShopifyInventoryLocationResolver).
    */

    'inventory_location_gid' => env('SHOPIFY_INVENTORY_LOCATION_GID'),

    'inventory_set_batch_size' => max(1, min(250, (int) env('SHOPIFY_INVENTORY_SET_BATCH_SIZE', 100))),

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

    /*
    |--------------------------------------------------------------------------
    | Staff order report (POS attribution buckets)
    |--------------------------------------------------------------------------
    |
    | Maps Shopify REST order user_id values to report columns. Quick Sale and
    | online channels are classified separately in ShopifyOrderStaffBucketClassifier.
    |
    */

    'staff_order_report' => [
        'timezone' => 'America/Toronto',
        'staff' => [
            '134032556113' => ['key' => 'alex_hui', 'label' => 'Alex Hui'],
            '134032425041' => ['key' => 'kaz_dizaro', 'label' => 'Kaz Dizaro'],
            '132966613073' => ['key' => 'po_ming_lam', 'label' => 'Po Ming Lam'],
        ],
        'extra_buckets' => [
            ['key' => 'quick_sale', 'label' => 'Quick Sale'],
            ['key' => 'online_store', 'label' => 'Online Store'],
            ['key' => 'shop', 'label' => 'Shop'],
            ['key' => 'pos_other', 'label' => 'POS (other)'],
        ],
        'cache_ttl_seconds' => max(60, (int) env('SHOPIFY_STAFF_ORDER_REPORT_CACHE_TTL', 300)),
    ],

];
