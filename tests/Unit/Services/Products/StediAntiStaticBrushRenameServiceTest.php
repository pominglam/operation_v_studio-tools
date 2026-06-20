<?php

declare(strict_types=1);

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\StediAntiStaticBrushRenameService;
use App\Support\Products\Storefront\StediAntiStaticBrushTitleResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('renames stedi anti-static brushes in dry run without saving', function (): void {
    $ms81 = new Product([
        'sku' => 'MS-81',
        'description' => 'Stedi Anti static brush',
        'type' => 'BRUSHES',
    ]);
    $ms82 = new Product([
        'sku' => 'MS-82',
        'description' => 'Stedi Anti static brush',
        'type' => 'BRUSHES',
    ]);

    $products = Mockery::mock(ProductRepository::class);
    $products->shouldReceive('findBySkus')->once()->andReturn(new Collection([$ms81, $ms82]));
    $products->shouldReceive('save')->never();

    $service = new StediAntiStaticBrushRenameService($products, new StediAntiStaticBrushTitleResolver);
    $result = $service->rename(apply: false, previewLimit: 10);

    expect($result['matched'])->toBe(2)
        ->and($result['changed'])->toBe(2)
        ->and($result['preview'][0]['new'])->toBe('Stedi Anti-Static Brush (Soft, Large Head)')
        ->and($result['preview'][1]['new'])->toBe('Stedi Anti-Static Brush (Soft, Small Head)')
        ->and($ms81->description)->toBe('Stedi Anti static brush');
});

it('applies stedi anti-static brush renames when apply is true', function (): void {
    $product = new Product([
        'sku' => 'MS-83',
        'description' => 'Stedi Anti static brush',
        'type' => 'BRUSHES',
    ]);

    $products = Mockery::mock(ProductRepository::class);
    $products->shouldReceive('findBySkus')->once()->andReturn(new Collection([$product]));
    $products->shouldReceive('save')->once()->with($product)->andReturn($product);

    $service = new StediAntiStaticBrushRenameService($products, new StediAntiStaticBrushTitleResolver);
    $result = $service->rename(apply: true, previewLimit: 10);

    expect($result['changed'])->toBe(1)
        ->and($product->description)->toBe('Stedi Anti-Static Brush (Bristle, Large Head)');
});
