<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductExternalContent;

it('persists manual description html in source other when preferred source is other', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070401',
        'sku' => 'MANUAL-1',
        'description' => 'Manual desc product',
        'vendor' => 'V',
        'preferred_description_source' => null,
    ]);

    ProductExternalContent::query()->create([
        'product_id' => $p->id,
        'source' => 'other',
        'source_url' => 'https://example.com/original',
        'title' => 'Existing other title',
        'description_html' => '<p>Old</p>',
        'attributes_json' => ['foo' => 'bar'],
    ]);

    $this->patchJson("/api/v1/products/{$p->uuid}/preferred-description-source", [
        'preferred_description_source' => 'other',
        'manual_description_html' => '<p>New manual text</p>',
    ])->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.preferred_description_source', 'other');

    $p->refresh();
    expect($p->preferred_description_source)->toBe('other');

    $other = ProductExternalContent::query()
        ->where('product_id', '=', $p->id)
        ->where('source', '=', 'other')
        ->first();

    expect($other)->not->toBeNull();
    expect((string) ($other?->description_html ?? ''))->toBe('<p>New manual text</p>');
    expect((string) ($other?->title ?? ''))->toBe('Existing other title');
    expect((string) ($other?->source_url ?? ''))->toBe('https://example.com/original');
    expect(is_array($other?->attributes_json ?? null) ? $other?->attributes_json : [])->toBe(['foo' => 'bar']);
});

it('validates manual_description_html type for preferred description source endpoint', function (): void {
    $p = Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070402',
        'sku' => 'MANUAL-2',
        'description' => 'Manual desc product 2',
        'vendor' => 'V',
    ]);

    $this->patchJson("/api/v1/products/{$p->uuid}/preferred-description-source", [
        'preferred_description_source' => 'other',
        'manual_description_html' => ['not', 'a', 'string'],
    ])->assertStatus(422);
});
