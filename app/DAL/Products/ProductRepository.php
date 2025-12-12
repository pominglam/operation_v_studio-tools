<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductImportRowDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProductRepository
{
    /**
     * @param array<int, ProductImportRowDTO> $rows
     */
    public function upsertImportedRows(array $rows): int;

    public function paginate(int $perPage): LengthAwarePaginator;

    public function flushAll(): void;
}


