<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

it('imports products from a CSV and persists them', function (): void {
    $csv = implode("\n", [
        'SKU,BARCODE,PRODUCT DESCRIPTION,TYPE,PRICE,ORDER,FILLED,EXTENDED',
        '5060358,4573102603586,HG 1/144 #13 Gundam Astray Blue Frame,HG,$10.13,2,2,$20.26',
        '5066295,4573102662958,HG 1/144 BLACK KNIGHT SQUAD Shi-ve.A,HG,$25.65,2,2,$51.30',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $response = $this->postJson('/api/v1/products/import', [
        'file' => $file,
    ]);

    $response->assertOk()->assertJson([
        'imported' => 2,
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => '5060358',
        'barcode' => '4573102603586',
    ]);

    $this->assertDatabaseHas('products', [
        'sku' => '5066295',
        'barcode' => '4573102662958',
    ]);
});

it('rejects a CSV missing required columns', function (): void {
    $csv = implode("\n", [
        'SKU,PRODUCT DESCRIPTION',
        '5060358,Some product',
    ]);

    $file = UploadedFile::fake()->createWithContent('products.csv', $csv, 'text/csv');

    $response = $this->postJson('/api/v1/products/import', [
        'file' => $file,
    ]);

    $response->assertStatus(422)->assertJson([
        'message' => 'Missing required column: BARCODE',
    ]);
});


