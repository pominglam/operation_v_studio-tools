<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\InventoryChecks\InventoryCheckRepository;

final class InventoryCheckDeleteService
{
    public function __construct(
        private readonly InventoryCheckRepository $inventoryChecks,
    ) {}

    public function deleteByUuid(string $uuid): void
    {
        $check = $this->inventoryChecks->findByUuidOrFail($uuid);
        $this->inventoryChecks->deleteSession($check);
    }
}
