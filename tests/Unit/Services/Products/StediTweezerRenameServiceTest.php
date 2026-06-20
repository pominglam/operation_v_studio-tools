<?php

declare(strict_types=1);

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\StediTweezerRenameService;
use App\Support\Products\Storefront\StediTweezerTitleResolver;
use Illuminate\Support\Collection;
use Tests\TestCase;

uses(TestCase::class);

it('renames stedi tweezers in dry run without saving', function (): void {
    $ms11 = new Product([
        'sku' => 'MS-11',
        'description' => 'Stedi Tweezers (Straight)',
        'type' => 'Tweezers',
    ]);
    $ms161 = new Product([
        'sku' => 'MS-161',
        'description' => 'Stedi Tweezers (Straight)',
        'type' => 'Tweezers',
    ]);

    $products = Mockery::mock(ProductRepository::class);
    $products->shouldReceive('findBySkus')->once()->andReturn(new Collection([$ms11, $ms161]));
    $products->shouldReceive('save')->never();

    $service = new StediTweezerRenameService($products, new StediTweezerTitleResolver);
    $result = $service->rename(apply: false, previewLimit: 10);

    expect($result['matched'])->toBe(2)
        ->and($result['changed'])->toBe(2)
        ->and($result['preview'][0]['new'])->toBe('Stedi Ultra-Precision Tweezers (Straight)')
        ->and($result['preview'][1]['new'])->toBe('Stedi Thick-Wall Tweezers (Straight)')
        ->and($ms11->description)->toBe('Stedi Tweezers (Straight)');
});

it('applies stedi tweezer renames when apply is true', function (): void {
    $product = new Product([
        'sku' => 'MS-162',
        'description' => 'Stedi Tweezers (Flat)',
        'type' => 'Tweezers',
    ]);

    $products = Mockery::mock(ProductRepository::class);
    $products->shouldReceive('findBySkus')->once()->andReturn(new Collection([$product]));
    $products->shouldReceive('save')->once()->with($product)->andReturn($product);

    $service = new StediTweezerRenameService($products, new StediTweezerTitleResolver);
    $result = $service->rename(apply: true, previewLimit: 10);

    expect($result['changed'])->toBe(1)
        ->and($product->description)->toBe('Stedi Thick-Wall Tweezers (Flat)');
});
