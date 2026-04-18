<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\InventoryChecks\InventoryCheckRepository;
use App\Models\InventoryCheck;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class InventoryCheckQueryService
{
    public function __construct(
        private readonly InventoryCheckRepository $inventoryChecks,
    ) {}

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->inventoryChecks->paginate($perPage);
    }

    public function findByUuidOrFail(string $uuid): InventoryCheck
    {
        return $this->inventoryChecks->findByUuidOrFail($uuid);
    }
}
