<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalAsset;
use Illuminate\Support\Facades\DB;

it('reorders plamod image assets and returns them in the new order', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090030',
        'sku' => 'SKU-REORDER-1',
        'barcode' => null,
        'description' => 'Test',
        'handle' => null,
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $a1 = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/'.$p->sku.'/x/a1.png',
        'filename' => 'a1.png',
        'mime_type' => 'image/png',
        'size_bytes' => 10,
        'checksum_sha256' => null,
        'sort_order' => 1,
    ]);
    $a2 = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/'.$p->sku.'/x/a2.png',
        'filename' => 'a2.png',
        'mime_type' => 'image/png',
        'size_bytes' => 10,
        'checksum_sha256' => null,
        'sort_order' => 2,
    ]);
    $a3 = ProductExternalAsset::query()->create([
        'product_id' => $p->id,
        'source' => 'plamod',
        'kind' => 'image',
        'storage_path' => 'plamod/extracted/'.$p->sku.'/x/a3.png',
        'filename' => 'a3.png',
        'mime_type' => 'image/png',
        'size_bytes' => 10,
        'checksum_sha256' => null,
        'sort_order' => 3,
    ]);

    // Reorder to: a3, a1, a2
    $this->putJson("/api/v1/products/{$p->uuid}/plamod/assets/order", [
        'asset_ids' => [(int) $a3->id, (int) $a1->id, (int) $a2->id],
    ])->assertOk()->assertJson(['ok' => true]);

    // Verify persisted sort_order values
    expect((int) DB::table('product_external_assets')->where('id', $a3->id)->value('sort_order'))->toBe(1);
    expect((int) DB::table('product_external_assets')->where('id', $a1->id)->value('sort_order'))->toBe(2);
    expect((int) DB::table('product_external_assets')->where('id', $a2->id)->value('sort_order'))->toBe(3);

    // And verify `/plamod` endpoint returns assets in that order
    $res = $this->getJson("/api/v1/products/{$p->uuid}/plamod")->assertOk();
    $ids = array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        $res->json('data.assets') ?? []
    );
    expect($ids)->toEqual([(int) $a3->id, (int) $a1->id, (int) $a2->id]);
});

it('validates reorder request payload', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000090031',
        'sku' => 'SKU-REORDER-2',
        'barcode' => null,
        'description' => 'Test',
        'handle' => null,
        'type' => null,
        'vendor' => 'Plamod',
        'published_on_shopify' => false,
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'available_qty' => null,
        'extended' => null,
    ]);

    $this->putJson("/api/v1/products/{$p->uuid}/plamod/assets/order", [])
        ->assertStatus(422);
});
