<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProductsQueryService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, string>  $types
     */
    public function paginate(int $perPage, ?string $search = null, array $types = [], ?string $sortBy = null, string $sortDir = 'asc'): LengthAwarePaginator
    {
        return $this->products->paginate($perPage, $search, $types, $sortBy, $sortDir);
    }

    /**
     * @return array<int, string>
     */
    public function distinctTypes(): array
    {
        return $this->products->distinctTypes();
    }
}
