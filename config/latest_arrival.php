<?php

declare(strict_types=1);

/**
 * Latest Arrivals sort within each PO (and PO push preview).
 *
 * Storefront collection: received POs only, newest received_date first; products on multiple
 * received POs use newest received PO only; unreceived POs are ignored;
 * then this grade sequence within each PO (newest product created_at within each grade).
 *
 * CCS toys → Sazabi bust (before all Gundams) → PG → Mega → MG … → HG
 * → SD/BB/EX-Standard → Kun DX → Macross (end of Gundam block)
 * → 30MM (Armored Core first) → 30MF → 30MS → 30MP → Figure-rise → Entry Grade → Pokemon → Keroro
 * → Action base → System base → LED → Option parts set Gunpla (last)
 */
return [

    'type_rank_display_order' => [100, 7, 69, 6, 65, 5, 4, 3, 2, 1, 25, 8],

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
        'MACROSS' => 25,
        'EG' => 8,
        'SD' => 2,
        'SDW' => 2,
        'BB' => 2,
        'EX-Standard' => 2,
        'KUN DX' => 1,
        '30MM' => 8,
        'ARMORED CORE' => 8,
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
        'KERORO' => 8,
        'CCS TOYS' => 100,
        'SAZABI BUST' => 100,
        'SYSTEM BASE' => 8,
        'LED' => 8,
        'OPTION PARTS SET' => 8,
    ],

    /**
     * When the product name derives one of these types, use it even if products.type is set
     * (e.g. OPTION PARTS SET GUNPLA mis-tagged as EG).
     *
     * @var array<int, string>
     */
    'prefer_derived_over_stored_types' => [
        'OPTION PARTS SET',
        'MACROSS',
        'ARMORED CORE',
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
        2 => [
            'SD' => 0,
            'BB' => 1,
            'SDW' => 2,
            'EX-STANDARD' => 3,
        ],
        100 => [
            'CCS-TOYS' => 0,
            'SAZABI-BUST' => 1,
        ],
        8 => [
            'ARMORED-CORE' => 0,
            '30MM' => 1,
            '30MF' => 2,
            '30MS' => 3,
            '30MP' => 4,
            'FIGURE-RISE' => 5,
            'ENTRY-GRADE' => 6,
            'EG' => 7,
            'POKEMON' => 8,
            'KERORO' => 9,
            'OPTION-PARTS' => 90,
            'ACTION-BASE' => 91,
            'SYSTEM-BASE' => 92,
            'LED' => 93,
            'OPTION-PARTS-SET' => 94,
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
