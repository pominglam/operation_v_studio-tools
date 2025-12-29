<?php

declare(strict_types=1);

namespace App\DAL\InventoryChecks;

use App\Models\InventoryCheck;
use App\Models\InventoryCheckItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InventoryCheckRepository
{
    public function create(InventoryCheck $check): InventoryCheck;

    public function createItem(InventoryCheckItem $item): InventoryCheckItem;

    public function paginate(int $perPage): LengthAwarePaginator;

    public function findByUuidOrFail(string $uuid): InventoryCheck;
}


