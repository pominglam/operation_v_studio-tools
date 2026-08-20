<?php

declare(strict_types=1);

use App\Support\Products\WaterDecalSkuNormalizer;

it('prefixes skus with WD-', function (): void {
    $normalizer = new WaterDecalSkuNormalizer;

    expect($normalizer->normalizeSku('MG-223'))->toBe('WD-MG-223')
        ->and($normalizer->normalizeSku('WD-MG-223'))->toBe('WD-MG-223')
        ->and($normalizer->normalizeSku('GHOST'))->toBe('WD-GHOST');
});

it('proposes water decal titles', function (): void {
    $normalizer = new WaterDecalSkuNormalizer;

    expect($normalizer->proposeDescription('MG Barbatos Lupus'))->toBe('Water decal - MG Barbatos Lupus')
        ->and($normalizer->proposeDescription('Water decal - MG Turn A'))->toBe('Water decal - MG Turn A');
});
