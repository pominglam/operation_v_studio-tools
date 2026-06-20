<?php

declare(strict_types=1);

use App\Models\Product;
use App\Support\Products\Storefront\StediTweezerTitleResolver;
use App\Support\Products\Storefront\ToolFamilyProductResolver;
use Tests\TestCase;

uses(TestCase::class);

it('maps stedi tweezer skus to option a display titles', function (): void {
    $resolver = new StediTweezerTitleResolver;

    expect($resolver->resolveTitle(new Product(['sku' => 'MS-11'])))->toBe('Stedi Ultra-Precision Tweezers (Straight)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-161'])))->toBe('Stedi Thick-Wall Tweezers (Straight)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-17'])))->toBe('Stedi Ultra-Precision Tweezers (Curved Arc)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-163'])))->toBe('Stedi Thick-Wall Tweezers (Curved)')
        ->and($resolver->resolveTitle(new Product(['sku' => 'MS-999'])))->toBeNull();
});

it('maps stedi tweezer skus to product lines', function (): void {
    $resolver = new StediTweezerTitleResolver;

    expect($resolver->resolveLine(new Product(['sku' => 'MS-11'])))->toBe('ultra-precision')
        ->and($resolver->resolveLine(new Product(['sku' => 'MS-17'])))->toBe('ultra-precision')
        ->and($resolver->resolveLine(new Product(['sku' => 'MS-161'])))->toBe('thick-wall')
        ->and($resolver->resolveLine(new Product(['sku' => 'MS-163'])))->toBe('thick-wall')
        ->and($resolver->resolveLine(new Product(['sku' => 'MS-999'])))->toBeNull();
});

it('keeps tweezer style tags valid after option a titles', function (): void {
    $styleResolver = new ToolFamilyProductResolver;
    $titles = new StediTweezerTitleResolver;

    $cases = [
        ['sku' => 'MS-11', 'style' => 'straight'],
        ['sku' => 'MS-12', 'style' => 'curved'],
        ['sku' => 'MS-14', 'style' => 'point'],
        ['sku' => 'MS-15', 'style' => 'point'],
        ['sku' => 'MS-16', 'style' => 'flat'],
        ['sku' => 'MS-17', 'style' => 'curved'],
        ['sku' => 'MS-160', 'style' => 'point'],
        ['sku' => 'MS-161', 'style' => 'straight'],
        ['sku' => 'MS-162', 'style' => 'flat'],
        ['sku' => 'MS-163', 'style' => 'curved'],
    ];

    foreach ($cases as $case) {
        $title = (string) $titles->resolveTitle(new Product(['sku' => $case['sku']]));
        $product = new Product([
            'sku' => $case['sku'],
            'type' => 'Tweezers',
            'description' => $title,
        ]);

        expect($styleResolver->resolveTweezerStyle($product))->toBe($case['style'], "Failed for {$case['sku']}");
    }
});
