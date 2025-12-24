<?php

declare(strict_types=1);

return [
    /**
     * Prices older than this are considered expired and must be re-fetched before use.
     */
    'ttl_days' => (int) env('PRICE_RESEARCH_TTL_DAYS', 14),

    /**
     * Local development convenience: if a run is stuck in "queued" (no worker),
     * allow the status endpoint to kick it off inline after a short delay.
     *
     * This is only applied when APP_ENV=local.
     */
    'local_inline_queue_fallback' => (bool) env('PRICE_RESEARCH_LOCAL_INLINE_FALLBACK', true),
    'local_queue_stuck_seconds' => (int) env('PRICE_RESEARCH_LOCAL_QUEUE_STUCK_SECONDS', 3),

    /**
     * Basic rate limiting for outbound requests to competitor sites.
     */
    'rate_limit' => [
        // Max requests per competitor site (site_key) per minute.
        'per_site_per_minute' => (int) env('PRICE_RESEARCH_SITE_RATE_LIMIT_PER_MINUTE', 10),
        // Optional per-site overrides (site_key => per-minute int).
        // Example: 'argama_hobby' => 20
        'per_site_overrides' => [],
    ],

    /**
     * Temporarily disable specific competitor crawlers (by site_key).
     * Comma-separated list via env for easy toggling in local/dev.
     *
     * Example: PRICE_RESEARCH_DISABLED_SITE_KEYS=canadian_gundam
     *
     * @var array<int, string>
     */
    'disabled_site_keys' => array_values(array_filter(array_map(
        static fn (string $v): string => trim($v),
        explode(',', (string) env('PRICE_RESEARCH_DISABLED_SITE_KEYS', '')),
    ), static fn (string $v): bool => $v !== '')),

    /**
     * Competitor sites to query.
     *
     * Keys are stable identifiers used in DB records.
     */
    'sites' => [
        'aliexpress' => [
            'name' => 'AliExpress',
            'base_url' => 'https://www.aliexpress.com',
        ],
        'gundam_hangar' => [
            'name' => 'Gundam Hangar',
            'base_url' => 'https://gundamhangar.com',
        ],
        'panda_hobby' => [
            'name' => 'Panda Hobby',
            'base_url' => 'https://pandahobby.ca',
        ],
        'canadian_gundam' => [
            'name' => 'Canadian Gundam',
            'base_url' => 'https://canadiangundam.com',
        ],
        'hobby_bee' => [
            'name' => 'Hobby Bee',
            'base_url' => 'https://hobby-bee.com',
        ],
        'hobby_wholesale' => [
            'name' => 'HobbyWholesale',
            'base_url' => 'https://hobbywholesale.com',
        ],
        'meeplemart' => [
            'name' => 'Meeplemart',
            'base_url' => 'https://www.meeplemart.com',
        ],
        'hobby_sense' => [
            'name' => 'Hobby Sense',
            'base_url' => 'https://hobbysense.ca',
        ],
        'argama_hobby' => [
            'name' => 'Argama Hobby',
            'base_url' => 'https://argamahobby.com',
        ],
    ],
];
