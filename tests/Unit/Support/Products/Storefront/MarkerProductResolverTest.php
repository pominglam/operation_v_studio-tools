<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\MarkerProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function markerTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies marker department SKUs and excludes joint reinforcement', function (): void {
    $resolver = new MarkerProductResolver;

    expect($resolver->belongsToMarkersDepartment(markerTestProduct(['sku' => 'MK-01', 'type' => 'MARKERS', 'description' => 'Soft tipped pure black'])))->toBeTrue()
        ->and($resolver->belongsToMarkersDepartment(markerTestProduct(['sku' => 'MS-58', 'type' => 'MARKERS', 'description' => 'Joint reinforcement'])))->toBeFalse()
        ->and($resolver->belongsToMarkersDepartment(markerTestProduct(['sku' => 'MS-70', 'type' => 'MARKERS', 'description' => 'Metallic space aluminum'])))->toBeTrue();
});

it('classifies marker type and tip tags', function (): void {
    $resolver = new MarkerProductResolver;

    expect($resolver->resolveMarkerType(markerTestProduct(['sku' => 'MK-01', 'type' => 'MARKERS', 'description' => 'Soft tipped pure black'])))->toBe('solid')
        ->and($resolver->resolveMarkerType(markerTestProduct(['sku' => 'MKF-01', 'type' => 'MARKERS', 'description' => 'Fluorescent green'])))->toBe('fluorescent')
        ->and($resolver->resolveMarkerType(markerTestProduct(['sku' => 'MKM-01', 'type' => 'MARKERS', 'description' => 'Metallic black'])))->toBe('metallic')
        ->and($resolver->resolveMarkerType(markerTestProduct(['sku' => 'DMM-20', 'type' => 'MARKERS', 'description' => 'Metallic gold'])))->toBe('metallic')
        ->and($resolver->resolveMarkerTip(markerTestProduct(['sku' => 'MK-01', 'type' => 'MARKERS', 'description' => 'Soft tipped pure black'])))->toBe('soft')
        ->and($resolver->resolveMarkerTip(markerTestProduct(['sku' => 'DMM-01', 'type' => 'MARKERS', 'description' => 'Stedi white'])))->toBe('hard')
        ->and($resolver->resolveMarkerTip(markerTestProduct(['sku' => 'MS-70', 'type' => 'MARKERS', 'description' => 'Metallic space aluminum'])))->toBe('hard')
        ->and($resolver->resolveMarkerTip(markerTestProduct(['sku' => 'DMM-99', 'type' => 'MARKERS', 'description' => 'Soft tipped experimental'])))->toBe('soft');
});
