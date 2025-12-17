<?php

declare(strict_types=1);

use App\Models\Product;

it('backfills missing product types based on description', function (): void {
    $p1 = Product::query()->create([
        'sku' => 'T1',
        'barcode' => null,
        'description' => 'Orphans HG 1/144 Something',
        'type' => null,
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $p2 = Product::query()->create([
        'sku' => 'T2',
        'barcode' => null,
        'description' => 'BB368 OO Gundam Seven Sword G',
        'type' => '',
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $p3 = Product::query()->create([
        'sku' => 'T3',
        'barcode' => null,
        'description' => 'Orphans HG 1/144 Should not override',
        'type' => 'RG',
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $p4 = Product::query()->create([
        'sku' => 'T4',
        'barcode' => null,
        'description' => 'Totally Unknown Product',
        'type' => null,
        'vendor' => 'Plamod',
        'price' => null,
        'order_qty' => null,
        'filled_qty' => null,
        'extended' => null,
    ]);

    $res = $this->postJson('/api/v1/products/backfill-types');
    $res->assertOk()->assertJson(['updated' => 3]);

    $this->assertDatabaseHas('products', ['id' => $p1->id, 'type' => 'Orphans HG']);
    $this->assertDatabaseHas('products', ['id' => $p2->id, 'type' => 'SD']);
    $this->assertDatabaseHas('products', ['id' => $p3->id, 'type' => 'RG']);
    $this->assertDatabaseHas('products', ['id' => $p4->id, 'type' => 'Others']);
});
