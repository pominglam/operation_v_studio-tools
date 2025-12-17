<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;

final class ProductTypeRecomputeService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTypeDerivationService $types,
    ) {}

    /**
     * Re-run type derivation across ALL products, overwriting existing type
     * only when a derived type exists.
     */
    public function recomputeAllTypes(): int
    {
        $updated = 0;
        $all = $this->products->listAll();

        foreach ($all as $product) {
            $derived = $this->types->deriveFromName($product->description);
            $isMissing = trim((string) ($product->type ?? '')) === '';
            if ($derived === null) {
                // Only set fallback for missing types; don't overwrite an existing type with "Others".
                if (! $isMissing) {
                    continue;
                }
                $derived = 'Others';
            }

            if (($product->type ?? '') === $derived) {
                continue;
            }

            $product->type = $derived;
            $this->products->save($product);
            $updated++;
        }

        return $updated;
    }
}
