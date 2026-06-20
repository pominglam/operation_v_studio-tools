<?php

declare(strict_types=1);

use App\Support\Products\Storefront\SandingProductTypeResolver;
use Tests\TestCase;

uses(TestCase::class);

it('classifies sanding product types from SKU patterns', function (): void {
    $resolver = new SandingProductTypeResolver;

    expect($resolver->resolve(makeClassifierProduct(['sku' => 'MS-B600', 'description' => 'Adhesive Sandpaper'])))->toBe('sheet')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'MS-E600', 'description' => 'Sanding stick'])))->toBe('stick-sponge')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'MS-C600', 'description' => 'Sponge Sandpaper'])))->toBe('stick-sponge')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'MS-A1', 'description' => 'Thin Sponge Sandpaper'])))->toBe('stick-sponge')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'MS-44', 'description' => 'Polishing File'])))->toBe('glass-file')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'MS-D20', 'description' => 'Carbon Fiber Sanding Board'])))->toBe('board-plate')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'MS-JD20B', 'description' => 'Aluminum Sanding Plate'])))->toBe('board-plate')
        ->and($resolver->resolve(makeClassifierProduct(['sku' => 'GH-KS3-A3A', 'description' => 'Kamiyasu-Sanding Stick Assortment'])))->toBe('stick-sponge');
});
