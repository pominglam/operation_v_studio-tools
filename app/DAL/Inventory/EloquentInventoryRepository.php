<?php

declare(strict_types=1);

namespace App\DAL\Inventory;

use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;

final class EloquentInventoryRepository implements InventoryRepository
{
    public function createLot(InventoryLot $lot): InventoryLot
    {
        $lot->save();

        return $lot;
    }

    public function saveLot(InventoryLot $lot): InventoryLot
    {
        $lot->save();

        return $lot;
    }

    public function createMovement(InventoryMovement $movement): InventoryMovement
    {
        $movement->save();

        return $movement;
    }

    public function countLotsForPurchaseOrderItems(array $purchaseOrderItemIds): int
    {
        if ($purchaseOrderItemIds === []) {
            return 0;
        }

        return InventoryLot::query()
            ->whereIn('purchase_order_item_id', $purchaseOrderItemIds)
            ->count();
    }

    public function deleteLotsForPurchaseOrderItems(array $purchaseOrderItemIds): int
    {
        if ($purchaseOrderItemIds === []) {
            return 0;
        }

        return InventoryLot::query()
            ->whereIn('purchase_order_item_id', $purchaseOrderItemIds)
            ->delete();
    }

    /**
     * @return Collection<int, InventoryLot>
     */
    public function listFifoLotsForProduct(int $productId): Collection
    {
        return InventoryLot::query()
            ->where('product_id', '=', $productId)
            ->where('source_type', '<>', 'negative_balance')
            ->where('qty_remaining', '>', 0)
            ->orderByRaw('received_at is null asc')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, InventoryLot>
     */
    public function listFifoLotsForProductForUpdate(int $productId): Collection
    {
        return InventoryLot::query()
            ->where('product_id', '=', $productId)
            ->where('source_type', '<>', 'negative_balance')
            ->where('qty_remaining', '>', 0)
            ->orderByRaw('received_at is null asc')
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function findOrCreateNegativeBalanceLot(int $productId): InventoryLot
    {
        /** @var InventoryLot|null $lot */
        $lot = InventoryLot::query()
            ->where('product_id', '=', $productId)
            ->where('source_type', '=', 'negative_balance')
            ->orderByDesc('id')
            ->first();

        if ($lot !== null) {
            return $lot;
        }

        $lot = new InventoryLot();
        $lot->product_id = $productId;
        $lot->purchase_order_item_id = null;
        $lot->source_type = 'negative_balance';
        $lot->unit_cost = null;
        $lot->shipping_per_unit = null;
        $lot->qty_received = 0;
        $lot->qty_remaining = 0;
        $lot->received_at = now();
        $lot->save();

        return $lot;
    }
}


