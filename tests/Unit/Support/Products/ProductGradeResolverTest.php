<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Products\ProductTypeDerivationService;
use App\Support\Products\ProductGradeResolver;

it('maps model kit types to canonical grade buckets', function (): void {
    $resolver = new ProductGradeResolver(new ProductTypeDerivationService);

    expect($resolver->resolveFromType('HGUC'))->toBe('HG');
    expect($resolver->resolveFromType('EX-Standard'))->toBe('SD');
    expect($resolver->resolveFromType('MGEX'))->toBe('MGEX');
});

it('derives grades from product descriptions', function (): void {
    $resolver = new ProductGradeResolver(new ProductTypeDerivationService);

    expect($resolver->resolveFromDescription('ENTRY GRADE 1/144 WING GUNDAM'))->toBe('EG');
    expect($resolver->resolveFromDescription('FULL MECHANICS 1/100 GUNDAM AERIAL'))->toBe('FM');
    expect($resolver->resolveFromDescription('EX-Standard 002 Aile Strike Gundam'))->toBe('SD');
    expect($resolver->resolveFromDescription('BB365 Sinanju'))->toBe('SD');
});

it('preserves NG grade when already stored', function (): void {
    $resolver = new ProductGradeResolver(new ProductTypeDerivationService);
    $product = new Product([
        'description' => '1/144 RX-78F00/E GUNDAM',
        'type' => 'HG',
        'grade' => 'NG',
        'main_type' => 'model kit',
    ]);

    expect($resolver->resolveFromProduct($product))->toBe('NG');
});
