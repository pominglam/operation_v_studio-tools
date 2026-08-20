<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\ProductGradeBackfillService;

it('backfills incorrect grades for model kits', function (): void {
    Product::query()->create([
        'sku' => 'GRADE-FIX-1',
        'description' => 'ENTRY GRADE 1/144 RX-78-2 GUNDAM',
        'main_type' => 'model kit',
        'type' => 'EG',
        'grade' => 'E',
    ]);
    Product::query()->create([
        'sku' => 'GRADE-FIX-2',
        'description' => 'BB365 Sinanju',
        'main_type' => 'model kit',
        'type' => 'SD',
        'grade' => 'MG',
    ]);

    $service = app(ProductGradeBackfillService::class);
    $result = $service->backfill(['GRADE-FIX-1', 'GRADE-FIX-2']);

    expect($result['matched'])->toBe(2);
    expect($result['updated'])->toBe(2);
    expect(Product::query()->where('sku', 'GRADE-FIX-1')->value('grade'))->toBe('EG');
    expect(Product::query()->where('sku', 'GRADE-FIX-2')->value('grade'))->toBe('SD');
});
