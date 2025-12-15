<?php

declare(strict_types=1);

use App\Models\PriceResearchQuoteReport;
use App\Models\Product;

it('can mark a quote report as handled', function (): void {
    $product = Product::query()->create([
        'sku' => 'RPT-1',
        'description' => 'Report handled test',
    ]);

    $report = PriceResearchQuoteReport::query()->create([
        'product_id' => $product->id,
        'product_uuid' => $product->uuid,
        'sku' => $product->sku,
        'site_key' => 'gundam_hangar',
        'site_name' => 'Gundam Hangar',
        'note' => 'Test report',
    ]);

    $this->patchJson("/api/v1/price-research/reports/{$report->id}/handled")
        ->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonPath('data.handled_at', fn ($v) => is_string($v) && $v !== '');

    $this->assertDatabaseHas('price_research_quote_reports', [
        'id' => $report->id,
    ]);
});

it('returns 404 when marking a missing report handled', function (): void {
    $this->patchJson('/api/v1/price-research/reports/999999999/handled')->assertStatus(404);
});
