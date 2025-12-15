<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;

final class ProductMaintenanceService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    public function flushAll(): void
    {
        $this->products->flushAll();
    }
}
