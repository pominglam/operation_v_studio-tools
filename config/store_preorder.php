<?php

declare(strict_types=1);

return [
    'collection_handle' => 'pre-orders',

    /** Max moves per collectionReorderProducts call (Shopify limit 250). */
    'collection_reorder_moves_limit' => 250,

    'collection_reorder_job_max_wait_seconds' => (int) env('SHOPIFY_STORE_PREORDERS_REORDER_JOB_WAIT_SECONDS', 120),

    'collection_reorder_job_poll_ms' => 1000,
];
