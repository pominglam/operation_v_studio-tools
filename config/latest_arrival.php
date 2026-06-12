<?php

declare(strict_types=1);

/**
 * Latest Arrivals sort within each PO (and PO push preview).
 *
 * Storefront collection: received POs only, newest received_date first; products on multiple
 * received POs use newest received PO only; unreceived POs are ignored;
 * then this grade sequence within each PO (newest product created_at within each grade).
 *
 * PG → Mega → MG (MGEX first) → RE → Full Mechanics → RG → HGUC → HG → SD/BB
 * → 30MM → 30MF → 30MS → Entry Grade → Pokemon → Figure-rise
 */
return [

    'type_rank_display_order' => [7, 69, 6, 65, 5, 4, 3, 2, 8],

    /**
     * Map products.type (or derived type) to a rank bucket. Unknown types use rank 8.
     */
    'type_to_rank' => [
        'PG' => 7,
        'MEGA' => 69,
        'MEGA SIZE MODEL' => 69,
        'MG' => 6,
        'MGEX' => 6,
        'MGSD' => 6,
        'RE' => 65,
        'FM' => 65,
        'RG' => 5,
        'HGUC' => 4,
        'HGBF' => 4,
        'HGCE' => 4,
        'HGAC' => 4,
        'HGFC' => 4,
        'HGBC' => 4,
        'HGAW' => 4,
        'ENTRY GRADE' => 8,
        'HG' => 3,
        'Orphans HG' => 3,
        'EG' => 8,
        'SD' => 2,
        'SDW' => 2,
        'BB' => 2,
        '30MM' => 8,
        '30MF' => 8,
        '30MS' => 8,
        '30MP' => 8,
        'FIGURE-RISE' => 8,
        'OPTION PARTS' => 8,
        'ACTION BASE' => 8,
        'NIPPER' => 8,
        'SANDING' => 8,
        'KEYCHAIN' => 8,
        'PLAMAX' => 8,
        'POKEMON' => 8,
        'EX-Standard' => 8,
    ],

    /**
     * Within the same rank bucket, lower sort index appears first (before created_at desc).
     */
    'type_within_rank_order' => [
        3 => [
            'ORPHANS-HG' => 0,
            'HG' => 1,
        ],
        6 => [
            'MGEX' => 0,
            'MGSD' => 1,
            'MG' => 2,
        ],
        65 => [
            'RE' => 0,
            'FM' => 1,
        ],
        8 => [
            '30MM' => 0,
            '30MF' => 1,
            '30MS' => 2,
            '30MP' => 3,
            'ENTRY-GRADE' => 4,
            'EG' => 5,
            'POKEMON' => 6,
            'FIGURE-RISE' => 7,
        ],
    ],

    'default_type_rank' => 8,

    'default_within_rank_order' => 50,

    /**
     * main_type values skipped by PO "Mark latest arrival" (still publishable; toggle on Products if needed).
     */
    'exclude_main_types_from_auto_latest_arrival' => [
        'tools',
    ],

    /** Shopify smart collection GID for Latest Arrivals (manual sort). Set SHOPIFY_LATEST_ARRIVALS_COLLECTION_GID in .env */
    'collection_gid' => env('SHOPIFY_LATEST_ARRIVALS_COLLECTION_GID'),

    /** Max moves per collectionReorderProducts call (Shopify limit 250). */
    'collection_reorder_moves_limit' => 250,

];
