<?php

declare(strict_types=1);

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use Illuminate\Http\UploadedFile;

it('imports JS PDF-copied text CSV with multiline descriptions', function (): void {
    $csv = implode("\n", [
        'Item Description Quantity Price Per Unit Total',
        'Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit 1 35 35',
        'The Sword of Rage SES10-RNF/XS Metal Frame',
        'Model Kit',
        '',
        '1 65 65',
        '',
        'Caesar Works 1/100 Lycoris Model Kit 1 40 40',
        '',
    ]);

    $file = UploadedFile::fake()->createWithContent('js.csv', $csv);

    $res = $this->postJson('/api/v1/purchase-orders/import', [
        'vendor' => 'JS',
        'file' => $file,
    ])->assertOk();

    $uuid = (string) ($res->json('purchase_order_uuid') ?? '');
    expect($uuid)->not()->toBe('');
    expect((int) ($res->json('items') ?? 0))->toBe(3);

    $po = PurchaseOrder::query()->where('uuid', $uuid)->firstOrFail();
    expect($po->vendor)->toBe('JS');

    $rows = PurchaseOrderItem::query()
        ->where('purchase_order_id', $po->id)
        ->orderBy('id')
        ->get();
    expect($rows)->toHaveCount(3);
    expect($rows[0]->qty_ordered)->toBe(1);
    expect((string) $rows[0]->unit_cost)->toBe('35.0000');
    expect($rows[1]->qty_ordered)->toBe(1);
    expect((string) $rows[1]->unit_cost)->toBe('65.0000');

    $products = Product::query()->where('vendor', 'JS')->orderBy('id')->get();
    expect($products)->toHaveCount(3);
    expect((string) $products[0]->description)->toBe('Black Trojan 1/100 Gangsuosi (Gundzilla) Model Kit');
    expect((string) $products[1]->description)->toBe('The Sword of Rage SES10-RNF/XS Metal Frame Model Kit');
    expect((string) $products[2]->description)->toBe('Caesar Works 1/100 Lycoris Model Kit');

    foreach ($products as $p) {
        expect((string) $p->sku)->toStartWith('JS-');
    }
});

