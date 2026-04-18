<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Http\UploadedFile;

it('imports a Stedi Tools purchase order CSV', function (): void {
    // Existing product so we link line items correctly.
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000441111',
        'sku' => 'MS-104',
        'barcode' => 'E2E-111',
        'description' => 'Stedi Item',
        'type' => null,
        'vendor' => 'Stedi',
        'published_on_shopify' => false,
        'is_ready' => false,
        'latest_arrival' => false,
        'available_qty' => 0,
    ]);

    $csv = implode("\n", [
        'Stedi Tools,,,,,',
        'Product description,SKU,Contain,Wholesale price HKD,Order qty,Amount',
        'Some item,MS-104,?, HK$30.73 ,60," HK$1,843.81 "',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('stedi.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'Stedi',
        'file' => $file,
    ]);

    $res->assertOk();
    $res->assertJsonPath('items', 1);
});
