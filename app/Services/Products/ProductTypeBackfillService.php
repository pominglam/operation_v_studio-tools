<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;

final class ProductTypeBackfillService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTypeDerivationService $types,
    ) {}

    public function backfillMissingTypes(): int
    {
        $updated = 0;
        $missing = $this->products->listMissingType();

        foreach ($missing as $product) {
            $derived = $this->types->deriveFromName($product->description);
            $next = $derived ?? 'Others';

            $product->type = $next;
            $this->products->save($product);
            $updated++;
        }

        return $updated;
    }
}
