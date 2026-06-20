<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\AirbrushProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function airbrushTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'tools',
        'type' => 'TOOLS',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies airbrush department SKUs', function (): void {
    $resolver = new AirbrushProductResolver;

    expect($resolver->belongsToAirbrushDepartment(airbrushTestProduct(['sku' => 'PT-AB', 'type' => 'PAINT', 'description' => 'Wash-free airbrush'])))->toBeTrue()
        ->and($resolver->belongsToAirbrushDepartment(airbrushTestProduct(['sku' => 'AB-D03', 'type' => 'PAINT', 'description' => 'Needles 0.3mm'])))->toBeTrue()
        ->and($resolver->belongsToAirbrushDepartment(airbrushTestProduct(['sku' => 'GHAD-39', 'type' => 'AIRBRUSH', 'description' => 'Gaahleri airbrush'])))->toBeTrue()
        ->and($resolver->belongsToAirbrushDepartment(airbrushTestProduct(['sku' => 'MS-25', 'type' => 'Drill', 'description' => 'Hand drill'])))->toBeFalse();
});

it('classifies airbrush tool vs supply roles', function (): void {
    $resolver = new AirbrushProductResolver;

    expect($resolver->resolveRole(airbrushTestProduct(['sku' => 'PT-AB', 'type' => 'PAINT', 'description' => 'Wash-free airbrush'])))->toBe('tool')
        ->and($resolver->resolveRole(airbrushTestProduct(['sku' => 'AB-D05', 'type' => 'PAINT', 'description' => 'Needles 0.5mm'])))->toBe('supply')
        ->and($resolver->resolveRole(airbrushTestProduct(['sku' => 'MS-B50', 'type' => 'PAINT', 'description' => 'Bottles with needles'])))->toBe('supply');
});
