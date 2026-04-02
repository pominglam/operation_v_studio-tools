<?php

declare(strict_types=1);

namespace App\DAL\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
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
    public function paginate(int $perPage, string $sortDir = 'desc', string $sortBy = 'created'): LengthAwarePaginator
    {
        $sortDir = strtolower(trim($sortDir)) === 'asc' ? 'asc' : 'desc';
        $sortBy = strtolower(trim($sortBy));
        if (! in_array($sortBy, ['created', 'ordered'], true)) {
            $sortBy = 'created';
        }

        $q = PurchaseOrder::query()->withCount('items');

        if ($sortBy === 'ordered') {
            // Keep undated orders at the end for both directions.
            $q->orderByRaw('ordered_date is null asc')
                ->orderBy('ordered_date', $sortDir)
                ->orderBy('created_at', 'desc');
        } else {
            $q->orderBy('created_at', $sortDir);
        }

        return $q->paginate(perPage: $perPage);
    }

    /**
     * @return array<int, string>
     */
    public function distinctVendors(): array
    {
        /** @var array<int, string|null> $vendors */
        $vendors = PurchaseOrder::query()
            ->select('vendor')
            ->whereNotNull('vendor')
            ->distinct()
            ->orderBy('vendor')
            ->pluck('vendor')
            ->all();

        $vendors = array_map(static fn (?string $v): string => trim((string) $v), $vendors);
        $vendors = array_values(array_unique(array_filter($vendors, static fn (string $v): bool => $v !== '')));
        sort($vendors);

        return $vendors;
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

    /**
     * @return array<int, string>
     */
    public function listItemSkusByUuid(string $uuid): array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return [];
        }

        /** @var array<int, string> $rows */
        $rows = DB::table('purchase_orders')
            ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_orders.uuid', '=', $uuid)
            ->pluck('purchase_order_items.sku')
            ->all();

        $out = [];
        foreach ($rows as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $out[] = $sku;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<int, int>
     */
    public function listProductIdsByUuid(string $uuid): array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return [];
        }

        /** @var array<int, int|string|null> $rows */
        $rows = DB::table('purchase_orders')
            ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->where('purchase_orders.uuid', '=', $uuid)
            ->whereNotNull('purchase_order_items.product_id')
            ->pluck('purchase_order_items.product_id')
            ->all();

        $out = [];
        foreach ($rows as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    public function delete(PurchaseOrder $po): void
    {
        $po->delete();
    }
}


