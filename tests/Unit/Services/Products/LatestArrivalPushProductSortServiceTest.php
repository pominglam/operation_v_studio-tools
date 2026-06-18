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
        ['sku' => 'KR-1', 'type_rank' => 8, 'type_label' => 'KERORO', 'product_created_at' => '2026-04-20T12:00:00+00:00'],
        ['sku' => 'EG-1', 'type_rank' => 8, 'type_label' => 'EG', 'product_created_at' => '2026-04-01T12:00:00+00:00'],
        ['sku' => 'MS-1', 'type_rank' => 8, 'type_label' => '30MS', 'product_created_at' => '2026-03-01T12:00:00+00:00'],
        ['sku' => 'MF-1', 'type_rank' => 8, 'type_label' => '30MF', 'product_created_at' => '2026-02-01T12:00:00+00:00'],
        ['sku' => 'MM-1', 'type_rank' => 8, 'type_label' => '30MM', 'product_created_at' => '2026-01-01T12:00:00+00:00'],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['MM-1', 'MF-1', 'MS-1', 'FR-1', 'EG-1', 'PK-1', 'KR-1']);
});

it('places Figure-rise immediately after 30MM 30MF 30MS and 30MP', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        ['sku' => 'PK-1', 'type_rank' => 8, 'type_label' => 'POKEMON', 'product_created_at' => '2026-06-01T12:00:00+00:00'],
        ['sku' => 'FR-1', 'type_rank' => 8, 'type_label' => 'FIGURE-RISE', 'product_created_at' => '2026-05-01T12:00:00+00:00'],
        ['sku' => 'MP-1', 'type_rank' => 8, 'type_label' => '30MP', 'product_created_at' => '2026-04-01T12:00:00+00:00'],
        ['sku' => 'MS-1', 'type_rank' => 8, 'type_label' => '30MS', 'product_created_at' => '2026-03-01T12:00:00+00:00'],
        ['sku' => 'MF-1', 'type_rank' => 8, 'type_label' => '30MF', 'product_created_at' => '2026-02-01T12:00:00+00:00'],
        ['sku' => 'MM-1', 'type_rank' => 8, 'type_label' => '30MM', 'product_created_at' => '2026-01-01T12:00:00+00:00'],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['MM-1', 'MF-1', 'MS-1', 'MP-1', 'FR-1', 'PK-1']);
});

it('places Macross at the end of the Gundam block after Kun DX', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $hg = new Product([
        'sku' => '5068317',
        'type' => 'HG',
        'description' => 'HG 1/144 GQuuuuuuX',
        'created_at' => Carbon::parse('2026-06-01'),
    ]);
    $sd = new Product([
        'sku' => '5061786',
        'type' => 'SD',
        'description' => 'SD GUNDAM EX-STANDARD WING GUNDAM',
        'created_at' => Carbon::parse('2026-05-01'),
    ]);
    $kun = new Product([
        'sku' => '5065118',
        'type' => 'Others',
        'description' => '1/1 GUNPLA-KUN DX SET (WITH RUNNER STAND)',
        'created_at' => Carbon::parse('2026-04-01'),
    ]);
    $macross = new Product([
        'sku' => '5069168',
        'type' => 'HG',
        'description' => 'HG 1/100 VF-31C SIEGFRIED (MIRAGE FARINA JENIUS)',
        'created_at' => Carbon::parse('2026-03-01'),
    ]);
    $thirtyMm = new Product([
        'sku' => 'MM-1',
        'type' => '30MM',
        'description' => '30MM kit',
        'created_at' => Carbon::parse('2026-02-01'),
    ]);

    expect($service->typeLabelForProduct($macross))->toBe('MACROSS')
        ->and($service->typeRankForProduct($macross))->toBe(25);

    $sorted = $service->sortProducts([$thirtyMm, $macross, $kun, $sd, $hg]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        '5068317',
        '5061786',
        '5065118',
        '5069168',
        'MM-1',
    ]);
});

it('places EX-Standard with SD immediately after HG and Kun DX after SD block', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $hg = new Product([
        'sku' => 'HG-1',
        'type' => 'HG',
        'description' => 'HG kit',
        'created_at' => Carbon::parse('2026-06-01'),
    ]);
    $sd = new Product([
        'sku' => 'SD-1',
        'type' => 'SD',
        'description' => 'SD kit',
        'created_at' => Carbon::parse('2026-05-01'),
    ]);
    $ex = new Product([
        'sku' => 'EX-1',
        'type' => 'EX-Standard',
        'description' => 'EX-Standard 002 Aile Strike Gundam',
        'created_at' => Carbon::parse('2026-04-01'),
    ]);
    $kun = new Product([
        'sku' => 'KUN-1',
        'type' => 'Others',
        'description' => '1/1 GUNPLA-KUN DX SET',
        'created_at' => Carbon::parse('2026-03-01'),
    ]);
    $pokemon = new Product([
        'sku' => 'PK-1',
        'type' => 'POKEMON',
        'description' => 'Pokemon kit',
        'created_at' => Carbon::parse('2026-02-01'),
    ]);

    $sorted = $service->sortProducts([$pokemon, $kun, $ex, $sd, $hg]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        'HG-1',
        'SD-1',
        'EX-1',
        'KUN-1',
        'PK-1',
    ]);
});

it('places Keroro after Pokemon and action base after option parts at the end of rank 8', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        ['sku' => 'AB-1', 'type_rank' => 8, 'type_label' => 'ACTION BASE', 'product_created_at' => '2026-06-01T12:00:00+00:00'],
        ['sku' => 'OP-1', 'type_rank' => 8, 'type_label' => 'OPTION PARTS', 'product_created_at' => '2026-05-01T12:00:00+00:00'],
        ['sku' => 'KR-1', 'type_rank' => 8, 'type_label' => 'KERORO', 'product_created_at' => '2026-04-01T12:00:00+00:00'],
        ['sku' => 'PK-1', 'type_rank' => 8, 'type_label' => 'POKEMON', 'product_created_at' => '2026-03-01T12:00:00+00:00'],
        ['sku' => 'MM-1', 'type_rank' => 8, 'type_label' => '30MM', 'product_created_at' => '2026-02-01T12:00:00+00:00'],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['MM-1', 'PK-1', 'KR-1', 'OP-1', 'AB-1']);
});

it('places CCS toys and Sazabi bust before all Gundam grades', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $ccs = new Product([
        'sku' => 'CCS-1',
        'type' => 'Others',
        'description' => 'CCS EVANGELION Unit-02 Type II',
        'created_at' => Carbon::parse('2026-01-01'),
    ]);
    $sazabi = new Product([
        'sku' => 'SAZ-1',
        'type' => 'Others',
        'description' => 'Sazabi (Universal Century Saga)',
        'created_at' => Carbon::parse('2026-06-01'),
    ]);
    $pg = new Product([
        'sku' => 'PG-1',
        'type' => 'PG',
        'description' => 'PG kit',
        'created_at' => Carbon::parse('2026-07-01'),
    ]);

    $sorted = $service->sortProducts([$pg, $sazabi, $ccs]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        'CCS-1',
        'SAZ-1',
        'PG-1',
    ]);
});

it('places option parts set gunpla last even when stored type is EG', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $rows = [
        ['sku' => 'OPS-1', 'type_rank' => 8, 'type_label' => 'OPTION PARTS SET', 'product_created_at' => '2026-06-01T12:00:00+00:00'],
        ['sku' => 'LED-1', 'type_rank' => 8, 'type_label' => 'LED', 'product_created_at' => '2026-05-01T12:00:00+00:00'],
        ['sku' => 'AB-1', 'type_rank' => 8, 'type_label' => 'ACTION BASE', 'product_created_at' => '2026-04-01T12:00:00+00:00'],
        ['sku' => 'HG-1', 'type_rank' => 3, 'type_label' => 'HG', 'product_created_at' => '2026-03-01T12:00:00+00:00'],
    ];

    $sorted = $service->sortPreviewRows($rows);

    expect(array_column($sorted, 'sku'))->toBe(['HG-1', 'AB-1', 'LED-1', 'OPS-1']);
});

it('derives option parts set gunpla over stored EG for sort label', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $product = new Product([
        'sku' => '5068322',
        'type' => 'EG',
        'description' => 'OPTION PARTS SET GUNPLA 14 (GUNBARREL STRIKER)',
    ]);

    expect($service->typeLabelForProduct($product))->toBe('OPTION-PARTS-SET')
        ->and($service->typeRankForProduct($product))->toBe(8);
});

it('maps product types to rank buckets from config', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $pg = new Product(['type' => 'PG', 'description' => 'PG kit']);
    $hguc = new Product(['type' => 'HGUC', 'description' => 'HGUC kit']);
    $ex = new Product(['type' => 'EX-Standard', 'description' => 'EX-Standard kit']);
    $kun = new Product(['type' => 'Others', 'description' => '1/1 ZAKUPLA-KUN DX SET']);
    $ccs = new Product(['type' => 'Others', 'description' => 'CCS EVANGELION Unit-02 Type II']);
    $unknown = new Product(['type' => null, 'description' => 'Random item']);

    expect($service->typeRankForProduct($pg))->toBe(7)
        ->and($service->typeRankForProduct($hguc))->toBe(4)
        ->and($service->typeRankForProduct($ex))->toBe(2)
        ->and($service->typeRankForProduct($kun))->toBe(1)
        ->and($service->typeRankForProduct($ccs))->toBe(100)
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

it('places Armored Core at the beginning of the 30MM block within rank 8', function (): void {
    $service = new LatestArrivalPushProductSortService(new ProductTypeDerivationService);

    $armoredCore = new Product([
        'sku' => '5069200',
        'type' => '30MM',
        'description' => '30MM ARMORED CORE VI FIRES OF RUBICON',
    ]);
    $armoredCore->created_at = Carbon::parse('2026-06-01');
    $armoredCoreOlder = new Product([
        'sku' => '5067438',
        'type' => '30MM',
        'description' => '30MM ARMORED CORE VI FIRES OF RUBICON',
    ]);
    $armoredCoreOlder->created_at = Carbon::parse('2026-05-01');
    $thirtyMm = new Product([
        'sku' => 'MM-1',
        'type' => '30MM',
        'description' => '30MM bEXM-15 Cavalier Ver.',
    ]);
    $thirtyMm->created_at = Carbon::parse('2026-04-01');
    $thirtyMf = new Product([
        'sku' => 'MF-1',
        'type' => '30MF',
        'description' => '30MF LIBER ARCHER',
    ]);
    $thirtyMf->created_at = Carbon::parse('2026-03-01');

    expect($service->typeLabelForProduct($armoredCore))->toBe('ARMORED-CORE');

    $sorted = $service->sortProducts([$thirtyMf, $thirtyMm, $armoredCoreOlder, $armoredCore]);

    expect(array_map(static fn (Product $p): string => (string) $p->sku, $sorted))->toBe([
        '5069200',
        '5067438',
        'MM-1',
        'MF-1',
    ]);
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
