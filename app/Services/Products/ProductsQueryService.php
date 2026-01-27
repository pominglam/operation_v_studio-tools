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
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     */
    public function paginate(
        int $perPage,
        ?string $search = null,
        array $types = [],
        array $vendors = [],
        array $missing = [],
        ?string $sortBy = null,
        string $sortDir = 'asc',
        ?string $purchaseOrderUuid = null,
    ): LengthAwarePaginator
    {
        return $this->products->paginate($perPage, $search, $types, $vendors, $missing, $sortBy, $sortDir, $purchaseOrderUuid);
    }

    /**
     * @return array<int, string>
     */
    public function distinctTypes(): array
    {
        return $this->products->distinctTypes();
    }

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array
    {
        return $this->products->distinctVendors();
    }
}
