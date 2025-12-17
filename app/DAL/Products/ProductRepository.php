<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductRepository
{
    /**
     * @param  array<int, ProductImportRowDTO>  $rows
     */
    public function upsertImportedRows(array $rows): int;

    /**
     * @param  array<int, string>  $types
     */
    public function paginate(int $perPage, ?string $search = null, array $types = [], ?string $sortBy = null, string $sortDir = 'asc'): LengthAwarePaginator;

    /**
     * @param  array<int, string>  $types
     * @return Collection<int, Product>
     */
    public function listForExport(?string $search = null, array $types = [], ?string $sortBy = null, string $sortDir = 'asc'): Collection;

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

    public function create(Product $product): Product;

    public function findByUuidOrFail(string $uuid): Product;

    public function save(Product $product): Product;

    /**
     * @param  array<int, string>  $uuids
     */
    public function deleteByUuids(array $uuids): int;

    public function flushAll(): void;
}
