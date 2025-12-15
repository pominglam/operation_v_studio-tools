<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

it('lists imported products', function (): void {
    $csv = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,4573102603586,HG 1/144 #13 Gundam Astray Blue Frame,HG,$10.13,2,2,$20.26',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $this->postJson('/api/v1/products/import', [
        'file' => $file,
    ])->assertOk();

    $response = $this->getJson('/api/v1/products');

    $response
        ->assertOk()
        ->assertJsonPath('data.0.sku', '5060358')
        ->assertJsonPath('data.0.barcode', '4573102603586')
        ->assertJsonPath('data.0.type', 'HG');
});
