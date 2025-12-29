<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface ProductRepository
{
    /**
     * @param  array<int, ProductImportRowDTO>  $rows
     */
    public function upsertImportedRows(array $rows): int;

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     */
    public function paginate(int $perPage, ?string $search = null, array $types = [], array $vendors = [], array $missing = [], ?string $sortBy = null, string $sortDir = 'asc'): LengthAwarePaginator;

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     * @return LazyCollection<int, Product>
     */
    public function cursorForMissingInfo(?string $search = null, array $types = [], array $vendors = [], array $missing = []): LazyCollection;

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, Product>
     */
    public function listForExport(?string $search = null, array $types = [], ?string $sortBy = null, string $sortDir = 'asc'): Collection;

    /**
     * @return Collection<int, Product>
     */
    public function listMissingSellingPriceForExport(?string $sortBy = null, string $sortDir = 'asc'): Collection;

    /**
     * @return Collection<int, Product>
     */
    public function listMissingBarcodeForExport(?string $sortBy = null, string $sortDir = 'asc'): Collection;

    /**
     * Barcoded products for operational export: sorted by type then SKU.
     *
     * @return Collection<int, Product>
     */
    public function listBarcodedForExportSorted(): Collection;

    /**
     * Products for Shopify content export (requires selling price and eager-loaded PDP content/assets).
     *
     * @return Collection<int, Product>
     */
    public function listForShopifyContentExport(): Collection;

    /**
     * @return Collection<int, Product>
     */
    public function listMissingType(): Collection;

    /**
     * @return Collection<int, Product>
     */
    public function listAll(): Collection;

    /**
     * @return array<int, string>
     */
    public function distinctTypes(): array;

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array;

    /**
     * @param  array<int, string>  $skus
     * @return Collection<int, Product>
     */
    public function findBySkus(array $skus): Collection;

    /**
     * @param  array<int, string>  $barcodes
     * @return Collection<int, Product>
     */
    public function findByBarcodes(array $barcodes): Collection;

    /**
     * @return Collection<int, Product>
     */
    public function findByHandle(string $handle): Collection;

    /**
     * @return Collection<int, Product>
     */
    public function findBySkuAndVendor(string $sku, string $vendor): Collection;

    public function create(Product $product): Product;

    public function findByUuidOrFail(string $uuid): Product;

    public function save(Product $product): Product;

    /**
     * @param  array<int, string>  $uuids
     */
    public function deleteByUuids(array $uuids): int;

    /**
     * @param  array<int, string>  $uuids
     * @param  array<string, mixed>  $updates
     */
    public function updateByUuids(array $uuids, array $updates): int;

    public function flushAll(): void;
}
