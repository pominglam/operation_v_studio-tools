<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProductsQueryService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->products->paginate($perPage);
    }
}


