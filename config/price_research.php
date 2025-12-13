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
    ],

    /**
     * Competitor sites to query.
     *
     * Keys are stable identifiers used in DB records.
     */
    'sites' => [
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
    ],
];


