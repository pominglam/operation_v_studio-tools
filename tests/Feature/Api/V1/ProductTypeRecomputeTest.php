<?php

declare(strict_types=1);

use App\Models\Product;

it('recomputes product types for all products and can overwrite existing types', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'R1',
        'barcode' => null,
        'description' => 'Orphans HG 1/144 Something',
        'type' => 'RG',
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $p2 = Product::query()->create([
        'sku' => 'R2',
        'barcode' => null,
        'description' => 'Random Product With No Match',
        'type' => 'HG',
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $p3 = Product::query()->create([
        'sku' => 'R3',
        'barcode' => null,
        'description' => 'Totally Unknown Product',
        'type' => null,
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $res = $this->postJson('/api/v1/products/recompute-types');
    $res->assertOk()->assertJson(['updated' => 2]);

    $this->assertDatabaseHas('products', ['id' => $p1->id, 'type' => 'HGIBO']);
    $this->assertDatabaseHas('products', ['id' => $p2->id, 'type' => 'HG']);
    $this->assertDatabaseHas('products', ['id' => $p3->id, 'type' => 'Others']);

    // Idempotent: second run should have no further changes.
    $res2 = $this->postJson('/api/v1/products/recompute-types');
    $res2->assertOk()->assertJson(['updated' => 0]);
});
