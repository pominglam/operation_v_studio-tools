<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

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
        'sources' => ['bandai', 'hlj', 'gundamplanet', 'gundamhangar', 'plamod', 'competitor_price_research'],
    ]);

    $res->assertStatus(202);
    $res->assertJsonPath('ok', true);
    $res->assertJsonStructure(['batch_id', 'queued']);

    $batchId = (string) ($res->json('batch_id') ?? '');
    expect($batchId)->not->toBe('');

    $debug = DB::table('job_batch_items')
        ->where('batch_id', '=', $batchId)
        ->where('product_uuid', '=', '00000000-0000-0000-0000-000000070010')
        ->value('debug_log');
    expect(is_string($debug) ? $debug : '')->toContain('[job] sources=');
    expect(is_string($debug) ? $debug : '')->toContain('gundamplanet');
    expect(is_string($debug) ? $debug : '')->toContain('[gundamplanet][plan]');
    expect(is_string($debug) ? $debug : '')->toContain('www.gundamplanet.com/search?q=');
    expect(is_string($debug) ? $debug : '')->toContain('[gundamhangar][plan]');
    expect(is_string($debug) ? $debug : '')->toContain('server.gundamhangar.com/api/products?');

    Bus::assertBatched(function ($batch) {
        return $batch->name === 'recrawl_selected_products';
    });
});

