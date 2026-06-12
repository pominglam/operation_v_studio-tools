<?php

declare(strict_types=1);

use App\Models\Product;
use Tests\TestCase;

uses(TestCase::class);
use App\Services\Products\LatestArrivalPushProductSortService;
use App\Services\Products\ProductTypeDerivationService;
use Illuminate\Support\Carbon;

it('sorts preview rows by type rank order then created_at descending', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        [
            'sku' => 'EG-1',
            'type_rank' => 1,
            'product_created_at' => '2026-01-01T12:00:00+00:00',
        ],
        [
            'sku' => 'PG-1',
            'type_rank' => 7,
            'product_created_at' => '2026-05-01T12:00:00+00:00',
        ],
        [
            'sku' => 'HG-OLD',
            'type_rank' => 3,
            'product_created_at' => '2026-02-01T12:00:00+00:00',
        ],
        [
            'sku' => 'HG-NEW',
            'type_rank' => 3,
            'product_created_at' => '2026-04-01T12:00:00+00:00',
        ],
        [
            'sku' => 'RG-1',
            'type_rank' => 5,
            'product_created_at' => '2026-03-01T12:00:00+00:00',
        ],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe([
        'PG-1',
        'RG-1',
        'HG-NEW',
        'HG-OLD',
        'EG-1',
    ]);
});

it('derives RE from description when stored type is Others', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $re = new Product([
        'sku' => 'RE-OTHERS',
        'type' => 'Others',
        'description' => 'RE 1/100 Bawoo',
    ]);
    $re->created_at = Carbon::parse('2026-01-01');
    $mg = new Product([
        'sku' => 'MG-1',
        'type' => 'MG',
        'description' => 'MG kit',
    ]);
    $mg->created_at = Carbon::parse('2026-06-01');
    $rg = new Product([
        'sku' => 'RG-1',
        'type' => 'RG',
        'description' => 'RG kit',
    ]);
    $rg->created_at = Carbon::parse('2026-07-01');

    expect($service->typeLabelForProduct($re))->toBe('RE')
        ->and($service->typeRankForProduct($re))->toBe(65);

    $sorted = $service->sortProducts([$re, $rg, $mg]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        'MG-1',
        'RE-OTHERS',
        'RG-1',
    ]);
});

it('places RE and Full Mechanics immediately after MG', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $re = new Product([
        'sku' => 'RE-1',
        'type' => null,
        'description' => 'RE 1/100 Bawoo',
        'created_at' => Carbon::parse('2026-06-01'),
    ]);
    $fm = new Product([
        'sku' => 'FM-1',
        'type' => null,
        'description' => 'FULL MECHANICS 1/100 Forbidden Gundam',
        'created_at' => Carbon::parse('2026-05-01'),
    ]);
    $mg = new Product([
        'sku' => 'MG-1',
        'type' => 'MG',
        'description' => 'MG kit',
        'created_at' => Carbon::parse('2026-04-01'),
    ]);
    $rg = new Product([
        'sku' => 'RG-1',
        'type' => 'RG',
        'description' => 'RG kit',
        'created_at' => Carbon::parse('2026-07-01'),
    ]);

    $sorted = $service->sortProducts([$rg, $fm, $re, $mg]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        'MG-1',
        'RE-1',
        'FM-1',
        'RG-1',
    ]);
});

it('places Entry Grade and Pokemon after 30MM 30MF and 30MS within rank 8', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        ['sku' => 'FR-1', 'type_rank' => 8, 'type_label' => 'FIGURE-RISE', 'product_created_at' => '2026-06-01T12:00:00+00:00'],
        ['sku' => 'PK-1', 'type_rank' => 8, 'type_label' => 'POKEMON', 'product_created_at' => '2026-05-01T12:00:00+00:00'],
        ['sku' => 'EG-1', 'type_rank' => 8, 'type_label' => 'EG', 'product_created_at' => '2026-04-01T12:00:00+00:00'],
        ['sku' => 'MS-1', 'type_rank' => 8, 'type_label' => '30MS', 'product_created_at' => '2026-03-01T12:00:00+00:00'],
        ['sku' => 'MF-1', 'type_rank' => 8, 'type_label' => '30MF', 'product_created_at' => '2026-02-01T12:00:00+00:00'],
        ['sku' => 'MM-1', 'type_rank' => 8, 'type_label' => '30MM', 'product_created_at' => '2026-01-01T12:00:00+00:00'],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['MM-1', 'MF-1', 'MS-1', 'EG-1', 'PK-1', 'FR-1']);
});

it('maps product types to rank buckets from config', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $pg = new Product(['type' => 'PG', 'description' => 'PG kit']);
    $hguc = new Product(['type' => 'HGUC', 'description' => 'HGUC kit']);
    $unknown = new Product(['type' => null, 'description' => 'Random item']);

    expect($service->typeRankForProduct($pg))->toBe(7)
        ->and($service->typeRankForProduct($hguc))->toBe(4)
        ->and($service->typeRankForProduct($unknown))->toBe(8);
});

it('sorts MGEX before other MG within the same rank', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        [
            'sku' => 'MG-1',
            'type_rank' => 6,
            'type_label' => 'MG',
            'product_created_at' => '2026-06-01T12:00:00+00:00',
        ],
        [
            'sku' => 'MGEX-1',
            'type_rank' => 6,
            'type_label' => 'MGEX',
            'product_created_at' => '2026-01-01T12:00:00+00:00',
        ],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['MGEX-1', 'MG-1']);
});

it('places Mega Size Model immediately after PG', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $mega = new Product([
        'sku' => 'MEGA-1',
        'type' => null,
        'description' => 'Mega Size Model - 1/48 Scale Gundam',
        'created_at' => Carbon::parse('2026-06-01'),
    ]);
    $pg = new Product([
        'sku' => 'PG-1',
        'type' => 'PG',
        'description' => 'PG kit',
        'created_at' => Carbon::parse('2026-01-01'),
    ]);
    $mg = new Product([
        'sku' => 'MG-1',
        'type' => 'MG',
        'description' => 'MG kit',
        'created_at' => Carbon::parse('2026-05-01'),
    ]);

    $sorted = $service->sortProducts([$mg, $mega, $pg]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        'PG-1',
        'MEGA-1',
        'MG-1',
    ]);
});

it('groups Orphans HG together before regular HG within rank 3', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        [
            'sku' => 'HG-1',
            'type_rank' => 3,
            'type_label' => 'HG',
            'product_created_at' => '2026-06-01T12:00:00+00:00',
        ],
        [
            'sku' => 'ORPHAN-2',
            'type_rank' => 3,
            'type_label' => 'ORPHANS-HG',
            'product_created_at' => '2026-05-01T12:00:00+00:00',
        ],
        [
            'sku' => 'ORPHAN-1',
            'type_rank' => 3,
            'type_label' => 'ORPHANS-HG',
            'product_created_at' => '2026-06-02T12:00:00+00:00',
        ],
        [
            'sku' => 'HG-2',
            'type_rank' => 3,
            'type_label' => 'HG',
            'product_created_at' => '2026-04-01T12:00:00+00:00',
        ],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['ORPHAN-1', 'ORPHAN-2', 'HG-1', 'HG-2']);
});

it('groups 30MM then 30MF then 30MS then Figure-rise within rank 8 when no Entry Grade or Pokemon', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        ['sku' => 'FR-1', 'type_rank' => 8, 'type_label' => 'FIGURE-RISE', 'product_created_at' => '2026-06-01T12:00:00+00:00'],
        ['sku' => 'MS-2', 'type_rank' => 8, 'type_label' => '30MS', 'product_created_at' => '2026-05-01T12:00:00+00:00'],
        ['sku' => 'MF-1', 'type_rank' => 8, 'type_label' => '30MF', 'product_created_at' => '2026-04-01T12:00:00+00:00'],
        ['sku' => 'MS-1', 'type_rank' => 8, 'type_label' => '30MS', 'product_created_at' => '2026-03-01T12:00:00+00:00'],
        ['sku' => 'MM-1', 'type_rank' => 8, 'type_label' => '30MM', 'product_created_at' => '2026-02-01T12:00:00+00:00'],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['MM-1', 'MF-1', 'MS-2', 'MS-1', 'FR-1']);
});

it('sorts products by type rank then newest created_at within group', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $olderHg = new Product([
        'sku' => '111',
        'type' => 'HG',
        'description' => 'Older HG',
    ]);
    $olderHg->created_at = Carbon::parse('2026-01-10');
    $newerHg = new Product([
        'sku' => '222',
        'type' => 'HG',
        'description' => 'Newer HG',
    ]);
    $newerHg->created_at = Carbon::parse('2026-05-10');
    $rg = new Product([
        'sku' => '333',
        'type' => 'RG',
        'description' => 'RG kit',
    ]);
    $rg->created_at = Carbon::parse('2026-06-01');

    $sorted = $service->sortProducts([$olderHg, $rg, $newerHg]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        '333',
        '222',
        '111',
    ]);
});
