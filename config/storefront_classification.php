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
    ],

    /*
    |--------------------------------------------------------------------------
    | Dual-write legacy ERP tags
    |--------------------------------------------------------------------------
    |
    | When true, Shopify tags include main_type, type, and latest arrival
    | alongside ts:* storefront tags.
    |
    */
    'dual_write_legacy_tags' => true,

    /*
    |--------------------------------------------------------------------------
    | Public storefront base URL (customer-facing)
    |--------------------------------------------------------------------------
    */
    'storefront_base_url' => env('SHOPIFY_STOREFRONT_BASE_URL', 'https://operationvstudio.com'),
];
