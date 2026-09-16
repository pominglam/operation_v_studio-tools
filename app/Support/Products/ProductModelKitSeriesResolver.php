<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;
use App\Services\Products\ModelKitSeriesInferenceService;

final class ProductModelKitSeriesResolver
{
    public function __construct(
        private readonly ModelKitSeriesInferenceService $inference,
    ) {}

    public function resolve(Product $product, string $searchableText): ?string
    {
        $inferred = $this->inference->infer($product, $searchableText);
        if ($inferred !== null && $inferred->tagSlug === 'evangelion') {
            return $inferred->erpSeries;
        }

        $existing = trim((string) ($product->series ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        return $inferred?->erpSeries;
    }
}
