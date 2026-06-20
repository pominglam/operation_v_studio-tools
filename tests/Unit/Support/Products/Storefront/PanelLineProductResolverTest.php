<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\PanelLineProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function panelLineTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies panel liner accent pens as paint kind', function (): void {
    $resolver = new PanelLineProductResolver;

    expect($resolver->belongsToPanelLinersDepartment(panelLineTestProduct(['sku' => 'MP-01', 'description' => 'Panel Line Accent Pen (Blue)'])))->toBeTrue()
        ->and($resolver->resolveKind(panelLineTestProduct(['sku' => 'MP-01R', 'description' => 'Panel Line Accent Pen (Red)'])))->toBe('paint')
        ->and($resolver->belongsToPanelLinersDepartment(panelLineTestProduct(['sku' => 'MP-01B', 'description' => 'Panel line accent pen (blue)'])))->toBeTrue()
        ->and($resolver->resolveKind(panelLineTestProduct(['sku' => 'MP-01B', 'description' => 'Panel line accent pen (blue)'])))->toBe('paint');
});

it('classifies seepage line wiper pens as tool kind', function (): void {
    $resolver = new PanelLineProductResolver;

    expect($resolver->belongsToPanelLinersDepartment(panelLineTestProduct(['sku' => 'MP-02B', 'description' => 'Seepage line wiper pen'])))->toBeTrue()
        ->and($resolver->resolveKind(panelLineTestProduct(['sku' => 'MP-02B', 'description' => 'Seepage line wiper pen'])))->toBe('tool')
        ->and($resolver->belongsToPanelLinersDepartment(panelLineTestProduct(['sku' => 'MP-03R', 'description' => 'Seepage line wiper pen'])))->toBeTrue()
        ->and($resolver->resolveKind(panelLineTestProduct(['sku' => 'MP-03R', 'description' => 'Seepage line wiper pen'])))->toBe('tool');
});

it('does not classify liquid panel liner bottles as panel liners department', function (): void {
    $resolver = new PanelLineProductResolver;

    expect($resolver->belongsToPanelLinersDepartment(panelLineTestProduct(['sku' => 'MP-10', 'type' => 'Panel liner', 'description' => 'Panel liner brown'])))->toBeFalse()
        ->and($resolver->resolveKind(panelLineTestProduct(['sku' => 'MP-10', 'type' => 'Panel liner', 'description' => 'Panel liner brown'])))->toBeNull();
});
