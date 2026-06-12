<?php

declare(strict_types=1);

use App\Services\Plamod\PlamodPreorderCsvMergeService;
use Illuminate\Support\Facades\Storage;

it('merges preorder csv files by sku without duplicating rows', function (): void {
    $hub = <<<'CSV'
SKU,Product Name,Manufacturer
HUB001,Hub Kit,BANDAI
SHARED,Shared Kit,BANDAI
CSV;
    $bandai = <<<'CSV'
SKU,Product Name,Manufacturer
SHARED,Shared Kit Updated,BANDAI HOBBY
BANDAI001,Vigna Kit,BANDAI HOBBY
CSV;

    Storage::disk('local')->put('plamod/hub.csv', $hub);
    Storage::disk('local')->put('plamod/bandai.csv', $bandai);

    $mergedPath = app(PlamodPreorderCsvMergeService::class)->mergeStoragePaths(
        ['plamod/hub.csv', 'plamod/bandai.csv'],
        'plamod/merged.csv',
    );

    expect($mergedPath)->toBe('plamod/merged.csv');
    $contents = Storage::disk('local')->get('plamod/merged.csv');
    expect($contents)->toContain('HUB001');
    expect($contents)->toContain('BANDAI001');
    expect($contents)->toContain('Shared Kit Updated');
    expect(substr_count($contents, 'SHARED'))->toBe(1);
});

it('keeps hub fields when manufacturer csv only fills a subset of columns', function (): void {
    $hub = <<<'CSV'
SKU,Barcode,Product Name,Description,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Image URL
KOTO-AR007,4934054039180,SOFFIERA,Long hub description,ARCANADEA,2024-09-01,KOTOBUKIYA,Plastic Model Kit,71.20,68.46,https://example.com/hub.png
CSV;
    $bandai = <<<'CSV'
SKU,Barcode,Product Name,Series,Release Date,Manufacturer,Category,Price Stock,Price Preorder,Price Backorder,Quantity Preorder,PO Due Date,ETA Date,Image URL
KOTO-AR007,,,,,,,,,,,,,
CSV;

    Storage::disk('local')->put('plamod/hub-rich.csv', $hub);
    Storage::disk('local')->put('plamod/bandai-sparse.csv', $bandai);

    $mergedPath = app(PlamodPreorderCsvMergeService::class)->mergeStoragePaths(
        ['plamod/hub-rich.csv', 'plamod/bandai-sparse.csv'],
        'plamod/merged-rich.csv',
    );

    $contents = Storage::disk('local')->get($mergedPath);
    expect($contents)->toContain('Long hub description');
    expect($contents)->toContain('68.46');
});

it('ignores orphan csv continuation lines with invalid sku values', function (): void {
    $hub = <<<'CSV'
SKU,Barcode,Product Name,Description,Series,Release Date,Manufacturer,Category,Price Preorder,Image URL
5004801,4902425048017,1/144 Weapon Set,Broken description start
",Mobile Suit Gundam,2000-01-01,1,BANDAI HOBBY,Others,2.78,2.70,,80,2026-06-09,2027-01-31,https://example.com/orphan.png"
GOOD001,111,Valid Kit,Valid description,Valid Series,2026-01-01,BANDAI,Others,9.99,https://example.com/good.png
CSV;
    $bandai = <<<'CSV'
SKU,Product Name
GOOD001,Valid Kit
CSV;

    Storage::disk('local')->put('plamod/hub-broken.csv', $hub);
    Storage::disk('local')->put('plamod/bandai-min.csv', $bandai);

    $mergedPath = app(PlamodPreorderCsvMergeService::class)->mergeStoragePaths(
        ['plamod/hub-broken.csv', 'plamod/bandai-min.csv'],
        'plamod/merged-broken.csv',
    );

    $contents = Storage::disk('local')->get($mergedPath);
    expect($contents)->toContain('GOOD001');
    expect($contents)->not->toContain('Mobile Suit Gundam,2000-01-01,1,BANDAI HOBBY');
});

it('returns the single source path when only one csv is provided', function (): void {
    Storage::disk('local')->put('plamod/only.csv', "SKU,Product Name\nA1,Only\n");

    $path = app(PlamodPreorderCsvMergeService::class)->mergeStoragePaths(
        ['plamod/only.csv'],
        'plamod/ignored.csv',
    );

    expect($path)->toBe('plamod/only.csv');
});
