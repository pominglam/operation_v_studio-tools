<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\PaintProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function paintTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies paint department products from SKU patterns', function (): void {
    $resolver = new PaintProductResolver;

    expect($resolver->belongsToPaintsDepartment(paintTestProduct(['sku' => 'XG-001', 'type' => 'PAINT', 'description' => 'Super White'])))->toBeTrue()
        ->and($resolver->resolveProduct(paintTestProduct(['sku' => 'XPS-01', 'type' => 'PAINT', 'description' => 'Gray surfacer primer'])))->toBe('surfacer')
        ->and($resolver->resolveProduct(paintTestProduct(['sku' => 'XG-901', 'type' => 'PAINT', 'description' => 'Gloss Topcoat'])))->toBe('top-coat')
        ->and($resolver->resolveProduct(paintTestProduct(['sku' => 'MC-20', 'type' => 'PAINT', 'description' => 'Thinner slow drying'])))->toBe('thinner')
        ->and($resolver->resolveProduct(paintTestProduct(['sku' => 'MP-10', 'type' => 'Panel liner', 'description' => 'Panel liner brown'])))->toBe('panel-line')
        ->and($resolver->belongsToPaintsDepartment(paintTestProduct(['sku' => 'MP-01B', 'type' => 'Others', 'description' => 'Panel line accent pen (blue)'])))->toBeFalse()
        ->and($resolver->belongsToPaintsDepartment(paintTestProduct(['sku' => 'MP-02R', 'type' => 'Others', 'description' => 'Seepage line wiper pen'])))->toBeFalse()
        ->and($resolver->resolveProduct(paintTestProduct(['sku' => 'OVSP-00001', 'type' => 'BUNDLES', 'description' => 'Full paint set'])))->toBe('bundle')
        ->and($resolver->belongsToPaintsDepartment(paintTestProduct(['sku' => 'PT-AB', 'type' => 'PAINT', 'description' => 'Wash-free airbrush'])))->toBeFalse()
        ->and($resolver->belongsToPaintsDepartment(paintTestProduct(['sku' => 'MS-58', 'type' => 'MARKERS', 'description' => 'Joint reinforcement'])))->toBeFalse();
});

it('classifies paint application and finish types', function (): void {
    $resolver = new PaintProductResolver;

    expect($resolver->resolveApplications(paintTestProduct(['sku' => 'XG-001', 'type' => 'PAINT', 'description' => 'Super White'])))->toBe(['pre-thinned-airbrush', 'airbrush'])
        ->and($resolver->resolveApplications(paintTestProduct(['sku' => 'MC-01', 'type' => 'PAINT', 'description' => 'Matte red'])))->toBe(['hand', 'airbrush'])
        ->and($resolver->resolveApplications(paintTestProduct(['sku' => 'MMC-01', 'type' => 'PAINT', 'description' => 'Zirconium Silver'])))->toBe(['hand', 'airbrush'])
        ->and($resolver->resolvePaintType(paintTestProduct(['sku' => 'XSM-001', 'type' => 'PAINT', 'description' => 'Metallic Red'])))->toBe('metallic')
        ->and($resolver->resolvePaintType(paintTestProduct(['sku' => 'MMC-02', 'type' => 'PAINT', 'description' => 'NB Gold'])))->toBe('metallic')
        ->and($resolver->resolvePaintType(paintTestProduct(['sku' => 'XG-001', 'type' => 'PAINT', 'description' => 'Super White'])))->toBe('solid')
        ->and($resolver->resolvePaintType(paintTestProduct(['sku' => 'MP-20', 'type' => 'Panel liner', 'description' => 'Fluorescent orange panel liner'])))->toBe('fluorescent')
        ->and($resolver->resolveApplications(paintTestProduct(['sku' => 'XPS-01', 'type' => 'PAINT', 'description' => 'Surfacer'])))->toBe([]);
});
