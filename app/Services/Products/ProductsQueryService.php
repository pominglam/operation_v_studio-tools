<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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
        string $archivedFilter = 'active',
        ?string $poProductNovelty = null,
        ?string $ready = null,
        ?string $published = null,
        ?int $availableMin = null,
        ?int $availableMax = null,
        ?int $notArrived = null,
        ?int $notArrivedMin = null,
        ?int $reorder = null,
        bool $reorderGtOne = false,
        array $productFlags = [],
        array $shipmentMethods = [],
        bool $notArrivedIncludeDraftOrders = true,
        ?float $sellingPriceMin = null,
        ?float $sellingPriceMax = null,
        bool $missingLandedCost = false,
        bool $hasLandedCost = false,
        array $canonicalTaxonomyFilters = [],
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
            $archivedFilter,
            $poProductNovelty,
            $ready,
            $published,
            $availableMin,
            $availableMax,
            $notArrived,
            $notArrivedMin,
            $reorder,
            $reorderGtOne,
            $productFlags,
            $shipmentMethods,
            $notArrivedIncludeDraftOrders,
            $sellingPriceMin,
            $sellingPriceMax,
            $missingLandedCost,
            $hasLandedCost,
            $canonicalTaxonomyFilters,
        );
    }

    /**
     * @param  array<int, string>  $mainTypes
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $searchTerms
     * @param  array<int, string>  $productFlags
     * @param  array<int, string>  $shipmentMethods
     * @return Collection<int, \App\Models\Product>
     */
    public function listFiltered(
        ?string $search = null,
        array $mainTypes = [],
        array $types = [],
        array $vendors = [],
        array $missing = [],
        ?string $sortBy = null,
        string $sortDir = 'asc',
        array $purchaseOrderUuids = [],
        array $searchTerms = [],
        string $archivedFilter = 'active',
        ?string $poProductNovelty = null,
        ?string $ready = null,
        ?string $published = null,
        ?int $availableMin = null,
        ?int $availableMax = null,
        ?int $notArrived = null,
        ?int $notArrivedMin = null,
        ?int $reorder = null,
        bool $reorderGtOne = false,
        array $productFlags = [],
        array $shipmentMethods = [],
        bool $notArrivedIncludeDraftOrders = true,
        ?float $sellingPriceMin = null,
        ?float $sellingPriceMax = null,
        bool $missingLandedCost = false,
        bool $hasLandedCost = false,
        array $canonicalTaxonomyFilters = [],
    ): Collection {
        return $this->products->listFiltered(
            $search,
            $mainTypes,
            $types,
            $vendors,
            $missing,
            $sortBy,
            $sortDir,
            $purchaseOrderUuids,
            $searchTerms,
            $archivedFilter,
            $poProductNovelty,
            $ready,
            $published,
            $availableMin,
            $availableMax,
            $notArrived,
            $notArrivedMin,
            $reorder,
            $reorderGtOne,
            $productFlags,
            $shipmentMethods,
            $notArrivedIncludeDraftOrders,
            $sellingPriceMin,
            $sellingPriceMax,
            $missingLandedCost,
            $hasLandedCost,
            $canonicalTaxonomyFilters,
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

    /** @return array<int, string> */
    public function distinctDepartments(): array
    {
        return $this->products->distinctDepartments();
    }

    /** @return array<int, string> */
    public function distinctManufacturers(): array
    {
        return $this->products->distinctManufacturers();
    }

    /** @return array<int, string> */
    public function distinctFranchises(): array
    {
        return $this->products->distinctFranchises();
    }

    /** @return array<int, string> */
    public function distinctProductLines(): array
    {
        return $this->products->distinctProductLines();
    }

    /** @return array<int, string> */
    public function distinctSublines(): array
    {
        return $this->products->distinctSublines();
    }
}
