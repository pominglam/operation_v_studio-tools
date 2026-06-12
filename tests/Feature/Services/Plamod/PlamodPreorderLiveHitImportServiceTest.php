<?php

declare(strict_types=1);

use App\Models\PlamodPreorder;
use App\Services\Plamod\PlamodPreorderLiveHitImportService;

it('upserts live plamod search rows into the preorder snapshot', function (): void {
    $upserted = app(PlamodPreorderLiveHitImportService::class)->upsertResourceRows([
        [
            'sku' => '5057617',
            'product_name' => 'HGUC 1/144 #106 Loto Twin Set',
            'price_preorder' => '12.34',
            'quantity_preorder' => 3,
            'po_due_date' => '2026-06-09',
            'eta_date' => '2027-01-31',
            'not_in_import' => true,
        ],
    ]);

    expect($upserted)->toBe(1);

    $row = PlamodPreorder::query()->where('sku', '5057617')->firstOrFail();
    expect($row->product_name)->toBe('HGUC 1/144 #106 Loto Twin Set');
    expect((string) $row->price_preorder)->toBe('12.34');
    expect($row->quantity_preorder)->toBe(3);
    expect($row->dropped_at)->toBeNull();
});
