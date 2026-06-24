<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\StorefrontDepartment;
use App\Support\Products\Storefront\ToolFamilyProductResolver;
use Tests\TestCase;

uses(TestCase::class);

function toolFamilyTestProduct(array $attrs): Product
{
    return new Product(array_merge([
        'sku' => 'TEST-1',
        'description' => 'Test product',
        'main_type' => 'tools',
        'type' => 'TOOLS',
        'latest_arrival' => false,
    ], $attrs));
}

it('classifies tool family departments from SKU and type patterns', function (): void {
    $resolver = new ToolFamilyProductResolver;

    expect($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-130', 'type' => 'BRUSHES', 'description' => 'Fine brush'])))->toBe(StorefrontDepartment::BRUSHES)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-25', 'type' => 'Drill', 'description' => 'Hand drill handle'])))->toBe(StorefrontDepartment::DRILLS)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-11', 'type' => 'Tweezers', 'description' => 'Straight tweezers'])))->toBe(StorefrontDepartment::TWEEZERS)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-23', 'type' => 'Scribing', 'description' => 'Tungsten steel needle'])))->toBe(StorefrontDepartment::SCRIBING)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-150', 'type' => 'TOOLS', 'description' => 'Stedi Scriber 1 mm'])))->toBe(StorefrontDepartment::SCRIBING)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'ETC-01', 'type' => 'Others', 'main_type' => 'supplies', 'description' => 'Extra thin cement'])))->toBe(StorefrontDepartment::ADHESIVES)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-58', 'type' => 'MARKERS', 'description' => 'Joint reinforcement'])))->toBe(StorefrontDepartment::WORKSHOP_MISC)
        ->and($resolver->resolveDepartment(toolFamilyTestProduct(['sku' => 'MS-03', 'type' => 'Scribing', 'description' => 'Scribing tape 3mm'])))->toBeNull();
});

it('classifies tool family subtype tags', function (): void {
    $resolver = new ToolFamilyProductResolver;

    expect($resolver->resolveBrushType(toolFamilyTestProduct(['sku' => 'MS-81', 'type' => 'BRUSHES', 'description' => 'Anti static brush'])))->toBe('anti-static')
        ->and($resolver->resolveDrillType(toolFamilyTestProduct(['sku' => 'MS-25', 'type' => 'Drill', 'description' => 'Hand drill handle'])))->toBe('hand-drill')
        ->and($resolver->resolveTweezerStyle(toolFamilyTestProduct(['sku' => 'MS-12', 'type' => 'Tweezers', 'description' => 'Curve tweezers'])))->toBe('curved')
        ->and($resolver->resolveTweezerLine(toolFamilyTestProduct(['sku' => 'MS-11', 'type' => 'Tweezers', 'description' => 'Straight tweezers'])))->toBe('ultra-precision')
        ->and($resolver->resolveTweezerLine(toolFamilyTestProduct(['sku' => 'MS-163', 'type' => 'Tweezers', 'description' => 'Curved tweezers'])))->toBe('thick-wall')
        ->and($resolver->resolveScribingType(toolFamilyTestProduct(['sku' => 'MS-34', 'type' => 'Scribing', 'description' => 'Pusher 0.2 mm'])))->toBe('pusher')
        ->and($resolver->resolveScribingType(toolFamilyTestProduct(['sku' => 'MS-23', 'type' => 'Scribing', 'description' => 'Tungsten steel needle'])))->toBe('needle')
        ->and($resolver->resolveAdhesiveType(toolFamilyTestProduct(['sku' => 'MG-01', 'type' => 'Others', 'main_type' => 'supplies', 'description' => 'Adhesives low flow'])))->toBe('cement');
});
