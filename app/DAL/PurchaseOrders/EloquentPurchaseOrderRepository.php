<?php

declare(strict_types=1);

namespace App\DAL\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class EloquentPurchaseOrderRepository implements PurchaseOrderRepository
{
    public function create(PurchaseOrder $po): PurchaseOrder
    {
        $po->save();

        return $po;
    }

    public function save(PurchaseOrder $po): PurchaseOrder
    {
        $po->save();

        return $po;
    }

    public function createItem(PurchaseOrderItem $item): PurchaseOrderItem
    {
        $item->save();

        return $item;
    }

    public function findItemByIdOrFail(int $id): PurchaseOrderItem
    {
        /** @var PurchaseOrderItem|null $item */
        $item = PurchaseOrderItem::query()
            ->with('purchaseOrder')
            ->find($id);

        if ($item === null) {
            throw (new ModelNotFoundException())->setModel(PurchaseOrderItem::class, [$id]);
        }

        return $item;
    }

    /**
     * @return Collection<int, PurchaseOrderItem>
     */
    public function itemsForPurchaseOrderId(int $purchaseOrderId): Collection
    {
        /** @var Collection<int, PurchaseOrderItem> $items */
        $items = PurchaseOrderItem::query()
            ->where('purchase_order_id', '=', $purchaseOrderId)
            ->orderBy('id')
            ->get();

        return $items;
    }

    public function saveItem(PurchaseOrderItem $item): PurchaseOrderItem
    {
        $item->save();

        return $item;
    }

    /**
     * @return LengthAwarePaginator<PurchaseOrder>
     */
    public function paginate(int $perPage, string $sortDir = 'desc'): LengthAwarePaginator
    {
        $sortDir = strtolower(trim($sortDir)) === 'asc' ? 'asc' : 'desc';

        return PurchaseOrder::query()
            ->withCount('items')
            ->orderBy('created_at', $sortDir)
            ->paginate(perPage: $perPage);
    }

    public function findByUuidOrFail(string $uuid): PurchaseOrder
    {
        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::query()
            ->with(['items' => fn ($q) => $q->orderBy('id')->with('purchaseOrder'), 'items.product'])
            ->where('uuid', '=', $uuid)
            ->first();

        if ($po === null) {
            throw (new ModelNotFoundException())->setModel(PurchaseOrder::class, [$uuid]);
        }

        return $po;
    }

    public function countItems(int $purchaseOrderId): int
    {
        return PurchaseOrderItem::query()
            ->where('purchase_order_id', '=', $purchaseOrderId)
            ->count();
    }

    public function deleteItemsForPurchaseOrder(int $purchaseOrderId): int
    {
        return PurchaseOrderItem::query()
            ->where('purchase_order_id', '=', $purchaseOrderId)
            ->delete();
    }

    public function delete(PurchaseOrder $po): void
    {
        $po->delete();
    }
}


