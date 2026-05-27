<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Str;

it('clamps negative available_qty to zero before save', function (): void {
    $product = Product::query()->create([
        'uuid' => (string) Str::uuid(),
        'sku' => 'SKU-CLAMP-NEG',
        'description' => 'Clamp negative available qty',
        'type' => 'Others',
        'vendor' => 'Test',
        'available_qty' => 3,
    ]);

    $product->available_qty = -1;
    $product->save();

    $product->refresh();
    expect($product->available_qty)->toBe(0);
});
