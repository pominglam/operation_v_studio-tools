<?php

declare(strict_types=1);

use App\Support\Products\Storefront\SandingGritResolver;

it('maps grit values to sanding buckets', function (): void {
    $resolver = new SandingGritResolver;

    expect($resolver->bucketsFromText('Adhesive Sandpaper 400 grit'))->toBe(['coarse'])
        ->and($resolver->bucketsFromText('600-800 grit'))->toBe(['medium'])
        ->and($resolver->bucketsFromText('1000-1200 grit'))->toBe(['fine'])
        ->and($resolver->bucketsFromText('2000-2500 grit'))->toBe(['polish'])
        ->and($resolver->bucketsFromText('Ultra Fine Point Polishing File 10000 grit'))->toBe(['polish']);
});

it('returns multiple grit buckets for mixed packs', function (): void {
    $resolver = new SandingGritResolver;

    expect($resolver->bucketsFromText('400 / 600 / 800 grit'))->toBe(['coarse', 'medium'])
        ->and($resolver->bucketsFromText('400-600 / 600-800 / 1000-1200'))->toBe(['coarse', 'medium', 'fine']);
});

it('returns no grit buckets when description has no grit numbers', function (): void {
    $resolver = new SandingGritResolver;

    expect($resolver->bucketsFromText('Carbon Fiber Sanding Board 20mm'))->toBe([]);
});
