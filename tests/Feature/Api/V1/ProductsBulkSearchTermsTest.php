<?php

declare(strict_types=1);

use App\Models\Product;

it('filters products by multiple search terms (OR across terms)', function (): void {
    Product::query()->create([
        'sku' => 'SKU-ALPHA',
        'barcode' => '111',
        'description' => 'Action Base 5 1/144 Black',
        'vendor' => 'Plamod',
    ]);
    Product::query()->create([
        'sku' => 'SKU-BETA',
        'barcode' => '222',
        'description' => 'OLFA Knife',
        'vendor' => 'Plamod',
    ]);
    Product::query()->create([
        'sku' => 'SKU-GAMMA',
        'barcode' => '333',
        'description' => 'Unrelated product',
        'vendor' => 'Plamod',
    ]);

    $res = $this->getJson('/api/v1/products?per_page=100&search_terms[]=Action%20Base%205%201/144%20Black&search_terms[]=OLFA%20Knife');
    $res->assertOk()
        ->assertJsonMissing(['sku' => 'SKU-GAMMA']);

    $skus = collect($res->json('data'))->pluck('sku')->all();
    expect($skus)->toContain('SKU-ALPHA');
    expect($skus)->toContain('SKU-BETA');
});

it('rejects too many search terms', function (): void {
    $terms = implode('&', array_map(
        static fn (int $i): string => "search_terms[]=" . urlencode("TERM-{$i}"),
        range(1, 61),
    ));

    $this->getJson("/api/v1/products?per_page=100&{$terms}")->assertStatus(422);
});

