<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled storefront departments (phased rollout)
    |--------------------------------------------------------------------------
    |
    | Only departments listed here receive ts:* tags on Shopify push/export.
    | Phase 1 pilot: tapes and decals only.
    |
    */
    'enabled_departments' => [
        'tapes',
        'decals',
        'sanding',
        'cutting',
        'paints',
        'panel-liners',
        'markers',
        'brushes',
        'drills',
        'tweezers',
        'scribing',
        'adhesives',
        'workshop-misc',
        'airbrush',
        'weathering',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dual-write legacy ERP tags
    |--------------------------------------------------------------------------
    |
    | When true, legacy tags are included in classification output for staff review.
    | Shopify push always emits ts:* storefront tags only (never legacy main_type/type).
    |
    */
    'dual_write_legacy_tags' => false,

    /*
    |--------------------------------------------------------------------------
    | Public storefront base URL (customer-facing)
    |--------------------------------------------------------------------------
    */
    'storefront_base_url' => env('SHOPIFY_STOREFRONT_BASE_URL', 'https://operationvstudio.com'),

    /*
    |--------------------------------------------------------------------------
    | ovs-shopify-theme repo path (manifest verify, local dev)
    |--------------------------------------------------------------------------
    |
    | Sibling checkout used by shopify:storefront-collection-filters-manifest-verify
    | and Pest manifest tests. Override in Docker when the theme is mounted elsewhere.
    |
    */
    'ovs_shopify_theme_path' => env('OVS_SHOPIFY_THEME_PATH'),
];
