<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;

final class ProductBulkDeleteService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * @param array<int, string> $uuids
     */
    public function deleteByUuids(array $uuids): int
    {
        return $this->products->deleteByUuids($uuids);
    }
}


