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

it('returns the single source path when only one csv is provided', function (): void {
    Storage::disk('local')->put('plamod/only.csv', "SKU,Product Name\nA1,Only\n");

    $path = app(PlamodPreorderCsvMergeService::class)->mergeStoragePaths(
        ['plamod/only.csv'],
        'plamod/ignored.csv',
    );

    expect($path)->toBe('plamod/only.csv');
});
