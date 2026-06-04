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
     * @param  array<int, string>  $mainTypes
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $searchTerms
     * @param  array<int, string>  $productFlags
     * @param  array<int, string>  $shipmentMethods
     */
    public function paginate(
        int $perPage,
        ?string $search = null,
        array $mainTypes = [],
        array $types = [],
        array $vendors = [],
        array $missing = [],
        ?string $sortBy = null,
        string $sortDir = 'asc',
        array $purchaseOrderUuids = [],
        array $searchTerms = [],
        bool $includeArchived = false,
        ?string $poProductNovelty = null,
        ?string $ready = null,
        ?int $available = null,
        ?int $notArrived = null,
        ?int $reorder = null,
        bool $reorderGtOne = false,
        array $productFlags = [],
        array $shipmentMethods = [],
        bool $notArrivedIncludeDraftOrders = true,
    ): LengthAwarePaginator {
        return $this->products->paginate(
            $perPage,
            $search,
            $mainTypes,
            $types,
            $vendors,
            $missing,
            $sortBy,
            $sortDir,
            $purchaseOrderUuids,
            $searchTerms,
            $includeArchived,
            $poProductNovelty,
            $ready,
            $available,
            $notArrived,
            $reorder,
            $reorderGtOne,
            $productFlags,
            $shipmentMethods,
            $notArrivedIncludeDraftOrders,
        );
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
    public function distinctMainTypes(): array
    {
        return $this->products->distinctMainTypes();
    }

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array
    {
        return $this->products->distinctVendors();
    }

    /**
     * @return array<int, string>
     */
    public function distinctGrades(): array
    {
        return $this->products->distinctGrades();
    }

    /**
     * @return array<int, string>
     */
    public function distinctScales(): array
    {
        return $this->products->distinctScales();
    }

    /**
     * @return array<int, string>
     */
    public function distinctSeries(): array
    {
        return $this->products->distinctSeries();
    }
}
