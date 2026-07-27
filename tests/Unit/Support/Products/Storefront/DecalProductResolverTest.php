<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\DecalProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function decalTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'supplies',
        'type' => 'Others',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies decal softeners as dspiae brand', function (): void {
    $resolver = new DecalProductResolver;

    expect($resolver->resolveBrand(decalTestProduct(['sku' => 'ETC-03', 'main_type' => 'supplies'])))->toBe('dspiae')
        ->and($resolver->resolveBrand(decalTestProduct(['sku' => 'ETC-04', 'main_type' => 'supplies'])))->toBe('dspiae');
});

it('classifies generic water decals as unclassified brand', function (): void {
    $resolver = new DecalProductResolver;

    expect($resolver->resolveBrand(decalTestProduct([
        'sku' => 'WD-HGUC-001',
        'main_type' => 'water decals',
        'description' => 'Water decal - HGUC Penelope',
    ])))->toBe('unclassified');
});

it('classifies water decals with dspiae vendor as unclassified when title is generic', function (): void {
    $resolver = new DecalProductResolver;

    expect($resolver->resolveBrand(decalTestProduct([
        'sku' => 'WD-MG-224',
        'main_type' => 'water decals',
        'vendor' => 'Dspiae',
        'description' => 'Water decal - MG Turn A',
    ])))->toBe('unclassified');
});

it('classifies water decals with dspiae in the title as dspiae brand', function (): void {
    $resolver = new DecalProductResolver;

    expect($resolver->resolveBrand(decalTestProduct([
        'sku' => 'WD-MG-224',
        'main_type' => 'water decals',
        'vendor' => 'Dspiae',
        'description' => 'Dspiae water decal - MG Turn A',
    ])))->toBe('dspiae');
});

it('resolves product kind for softeners and sheets', function (): void {
    $resolver = new DecalProductResolver;

    expect($resolver->resolveProductKind(decalTestProduct(['sku' => 'ETC-03'])))->toBe('softener')
        ->and($resolver->resolveProductKind(decalTestProduct([
            'sku' => 'WD-HGUC-001',
            'main_type' => 'water decals',
        ])))->toBe('sheet');
});
