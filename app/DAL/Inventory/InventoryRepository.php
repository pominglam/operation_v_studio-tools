<?php

declare(strict_types=1);

namespace App\DAL\Inventory;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;

interface InventoryRepository
{
    public function createLot(InventoryLot $lot): InventoryLot;

    public function saveLot(InventoryLot $lot): InventoryLot;

    public function createMovement(InventoryMovement $movement): InventoryMovement;

    /**
     * @param  array<int, int>  $purchaseOrderItemIds
     */
    public function countLotsForPurchaseOrderItems(array $purchaseOrderItemIds): int;

    /**
     * @param  array<int, int>  $purchaseOrderItemIds
     */
    public function deleteLotsForPurchaseOrderItems(array $purchaseOrderItemIds): int;

    /**
     * FIFO lots for deduction: positive remaining only, oldest-first.
     *
     * @return Collection<int, InventoryLot>
     */
    public function listFifoLotsForProduct(int $productId): Collection;

    /**
     * Same as listFifoLotsForProduct, but locked FOR UPDATE (must be called inside a transaction).
     *
     * @return Collection<int, InventoryLot>
     */
    public function listFifoLotsForProductForUpdate(int $productId): Collection;

    public function findOrCreateNegativeBalanceLot(int $productId): InventoryLot;
}


