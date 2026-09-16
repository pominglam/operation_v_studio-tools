<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\ModelKitSeriesInferenceService;
use App\Support\Products\ProductModelKitSeriesResolver;

beforeEach(function (): void {
    $this->inference = app(ModelKitSeriesInferenceService::class);
});

it('infers Gundam Unicorn from title', function (): void {
    $product = new Product(['subline' => 'HGUC']);
    $text = 'HGUC 1/144 UNICORN GUNDAM DESTROY MODE';

    $proposal = $this->inference->infer($product, $text);

    expect($proposal)->not->toBeNull()
        ->and($proposal->erpSeries)->toBe('Gundam Unicorn')
        ->and($proposal->tagSlug)->toBe('gundam_unicorn')
        ->and($proposal->confidence)->toBe('high');
});

it('prefers SEED DESTINY over generic SEED', function (): void {
    $product = new Product(['subline' => 'HGCE']);
    $text = 'HGCE GUNDAM SEED DESTINY STRIKE FREEDOM';

    $proposal = $this->inference->infer($product, $text);

    expect($proposal)->not->toBeNull()
        ->and($proposal->erpSeries)->toBe('Gundam Seed Destiny')
        ->and($proposal->tagSlug)->toBe('gundam_seed_destiny');
});

it('infers Iron-Blooded Orphans from Barbatos', function (): void {
    $product = new Product(['subline' => 'HGIBO']);
    $text = 'HGIBO 1/144 GUNDAM BARBATOS';

    $proposal = $this->inference->infer($product, $text);

    expect($proposal)->not->toBeNull()
        ->and($proposal->erpSeries)->toBe('Iron-Blooded Orphans');
});

it('normalizes stored series via catalog slug', function (): void {
    $canonical = $this->inference->normalizeStoredSeries('Gundam Seed');

    expect($canonical)->toBe('Gundam SEED');
});

it('overrides stored Votoms series when the title is Asuka Shikinami', function (): void {
    $resolver = app(ProductModelKitSeriesResolver::class);
    $product = new Product([
        'description' => 'PLAMAX Asuka Shikinami Langley',
        'series' => 'Armored Trooper Votoms',
        'brand' => 'Armored Trooper Votoms',
        'type' => 'PLAMAX',
    ]);

    expect($resolver->resolve($product, $this->inference->searchableText($product)))->toBe('Evangelion');
});

it('infers Evangelion from Asuka Shikinami even when brand is Votoms', function (): void {
    $product = new Product([
        'description' => 'PLAMAX Asuka Shikinami Langley',
        'brand' => 'Armored Trooper Votoms',
        'type' => 'PLAMAX',
    ]);
    $text = $this->inference->searchableText($product);

    $proposal = $this->inference->infer($product, $text);

    expect($proposal)->not->toBeNull()
        ->and($proposal->erpSeries)->toBe('Evangelion')
        ->and($proposal->tagSlug)->toBe('evangelion');
});

it('uses subline hint when title lacks series signal', function (): void {
    $product = new Product(['subline' => 'HGAC']);
    $text = 'HGAC 1/144 GUNDAM SANDROCK CUSTOM';

    $proposal = $this->inference->infer($product, $text);

    expect($proposal)->not->toBeNull()
        ->and($proposal->erpSeries)->toBe('Gundam Wing')
        ->and($proposal->ruleId)->toBe('subline:hgac');
});
