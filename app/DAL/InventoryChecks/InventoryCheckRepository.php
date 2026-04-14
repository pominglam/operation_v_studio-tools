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

    public function save(InventoryCheck $check): InventoryCheck;

    public function saveItem(InventoryCheckItem $item): InventoryCheckItem;

    public function findItemInSessionOrFail(InventoryCheck $session, int $itemId): InventoryCheckItem;

    public function deleteItem(InventoryCheckItem $item): void;

    /**
     * Deletes all line items for the session, then the session row (FK-safe).
     */
    public function deleteSession(InventoryCheck $check): void;
}
