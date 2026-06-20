<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\StediAntiStaticBrushTitleResolver;
use Tests\TestCase;

uses(TestCase::class);

it('resolves distinct anti-static brush titles by sku', function (): void {
    $resolver = new StediAntiStaticBrushTitleResolver;

    expect($resolver->resolveTitle(new Product(['sku' => 'MS-81'])))->toBe('Stedi Anti-Static Brush (Soft, Large Head)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-82'])))->toBe('Stedi Anti-Static Brush (Soft, Small Head)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-83'])))->toBe('Stedi Anti-Static Brush (Bristle, Large Head)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-11'])))->toBeNull();
});
