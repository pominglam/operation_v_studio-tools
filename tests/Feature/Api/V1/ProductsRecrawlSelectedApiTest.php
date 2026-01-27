<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\Bus;

it('queues a recrawl batch for selected products and sources', function (): void {
    Product::query()->create([
        'uuid' => '00000000-0000-0000-0000-000000070010',
        'sku' => 'X1',
        'description' => 'Test',
        'vendor' => 'V',
    ]);

    Bus::fake();

    $res = $this->postJson('/api/v1/products/recrawl/selected', [
        'ids' => ['00000000-0000-0000-0000-000000070010'],
        'sources' => ['bandai', 'hlj', 'plamod', 'competitor_price_research'],
    ]);

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonStructure(['batch_id', 'queued']);

    Bus::assertBatched(function ($batch) {
        return $batch->name === 'recrawl_selected_products';
    });
});

