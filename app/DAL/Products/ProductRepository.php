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
     * @param  array<int, string>  $mainTypes
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     * @param  array<int, string>  $purchaseOrderUuids
     * @param  array<int, string>  $searchTerms
     */
    public function paginate(int $perPage, ?string $search = null, array $mainTypes = [], array $types = [], array $vendors = [], array $missing = [], ?string $sortBy = null, string $sortDir = 'asc', array $purchaseOrderUuids = [], array $searchTerms = [], bool $includeArchived = false, ?string $poProductNovelty = null, ?string $ready = null, ?int $available = null, ?int $notArrived = null, ?int $reorder = null, bool $reorderGtOne = false): LengthAwarePaginator;

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
     * Selected products for export.
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listByUuidsForExport(array $uuids, ?string $sortBy = null, string $sortDir = 'asc'): Collection;

    /**
     * Selected products missing barcode (same export shape as missing-barcode export).
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listMissingBarcodeByUuidsForExport(array $uuids, ?string $sortBy = null, string $sortDir = 'asc'): Collection;

    /**
     * Selected barcoded products for operational export: sorted by type then SKU (same as the full barcoded export).
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listBarcodedByUuidsForExportSorted(array $uuids): Collection;

    /**
     * Products for Shopify content export (requires selling price and eager-loaded PDP content/assets).
     *
     * @return Collection<int, Product>
     */
    public function listForShopifyContentExport(): Collection;

    /**
     * Shopify content export for a selected set of products (requires selling price and eager-loaded PDP content/assets).
     *
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function listForShopifyContentExportByUuids(array $uuids): Collection;

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
    public function distinctMainTypes(): array;

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array;

    /**
     * @return array<int, string>
     */
    public function distinctGrades(): array;

    /**
     * @return array<int, string>
     */
    public function distinctScales(): array;

    /**
     * @return array<int, string>
     */
    public function distinctSeries(): array;

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
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function findByUuids(array $uuids): Collection;

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

    /**
     * Override available qty using barcode counts.
     *
     * Behavior:
     * - Resets all products `available_qty` to 0
     * - Sets `available_qty` for products whose barcode exists in the list
     *
     * @param  array<int, array{barcode:string, qty:int}>  $barcodeCounts
     * @param  bool  $resetMissingToZero  When false, do NOT reset products not present in the file.
     * @return array{reset:int, updated:int}
     */
    public function overrideAvailableQtyFromBarcodeCounts(array $barcodeCounts, bool $resetMissingToZero = true): array;

    /**
     * Override available qty using barcode counts for a restricted set of products.
     *
     * Behavior:
     * - Resets `available_qty` to 0 only for the restricted products
     * - Sets `available_qty` for restricted products whose barcode exists in the list
     *
     * @param  array<int, int>  $restrictToProductIds
     * @param  array<int, array{barcode:string, qty:int}>  $barcodeCounts
     * @param  bool  $resetMissingToZero  When false, do NOT reset restricted products not present in the file.
     * @return array{reset:int, updated:int}
     */
    public function overrideAvailableQtyFromBarcodeCountsForProductIds(array $restrictToProductIds, array $barcodeCounts, bool $resetMissingToZero = true): array;
}
