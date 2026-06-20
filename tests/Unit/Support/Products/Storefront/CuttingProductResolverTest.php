<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\CuttingProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function cuttingTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies cutting categories from SKU patterns', function (): void {
    $resolver = new CuttingProductResolver;

    expect($resolver->resolveCategory(cuttingTestProduct(['sku' => 'MS-104', 'type' => 'NIPPER', 'description' => "Beginner's single blade nipper"])))->toBe('nipper')
        ->and($resolver->resolveCategory(cuttingTestProduct(['sku' => 'AK-1/5B', 'type' => 'TOOLS', 'description' => 'OLFA Knife'])))->toBe('knife')
        ->and($resolver->resolveCategory(cuttingTestProduct(['sku' => 'MS-22', 'type' => 'TOOLS', 'description' => 'Model 6mm pen knife set with carbon steel blades'])))->toBe('knife')
        ->and($resolver->resolveCategory(cuttingTestProduct(['sku' => 'MS-28', 'type' => 'TOOLS', 'description' => '4mm笔刀替换刀片'])))->toBe('blade')
        ->and($resolver->resolveCategory(cuttingTestProduct(['sku' => 'MS-23', 'type' => 'Scribing', 'description' => 'Needle'])))->toBeNull();
});

it('classifies cutting styles for nippers and knives', function (): void {
    $resolver = new CuttingProductResolver;

    expect($resolver->resolveStyles(cuttingTestProduct(['sku' => 'MS-104', 'type' => 'NIPPER', 'description' => "Beginner's single blade nipper"])))->toBe(['beginner', 'single-edge'])
        ->and($resolver->resolveStyles(cuttingTestProduct(['sku' => 'MS-112', 'type' => 'NIPPER', 'description' => 'Double-edged modeling nipper'])))->toBe(['double-edge'])
        ->and($resolver->resolveStyles(cuttingTestProduct(['sku' => 'MS-22', 'type' => 'TOOLS', 'description' => 'Model 6mm pen knife set'])))->toBe(['pen-knife'])
        ->and($resolver->resolveStyles(cuttingTestProduct(['sku' => 'MS-24', 'type' => 'TOOLS', 'description' => '4MM ceramic pen knife'])))->toBe(['ceramic']);
});
