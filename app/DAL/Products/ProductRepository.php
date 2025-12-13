<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepository
{
    /**
     * @param array<int, ProductImportRowDTO> $rows
     */
    public function upsertImportedRows(array $rows): int;

    public function paginate(int $perPage): LengthAwarePaginator;

    public function create(Product $product): Product;

    public function findByUuidOrFail(string $uuid): Product;

    public function save(Product $product): Product;

    /**
     * @param array<int, string> $uuids
     */
    public function deleteByUuids(array $uuids): int;

    public function flushAll(): void;
}


