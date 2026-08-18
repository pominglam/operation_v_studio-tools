<?php

declare(strict_types=1);

namespace App\DAL\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderCombinedPayment;
use App\Models\PurchaseOrderCombinedPaymentLine;
use App\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            throw (new ModelNotFoundException)->setModel(PurchaseOrderItem::class, [$id]);
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
    public function paginate(int $perPage, string $sortDir = 'desc', string $sortBy = 'ordered', array $vendors = [], array $statuses = []): LengthAwarePaginator
    {
        $sortDir = strtolower(trim($sortDir)) === 'asc' ? 'asc' : 'desc';
        $sortBy = strtolower(trim($sortBy));
        if (! in_array($sortBy, ['created', 'ordered', 'received', 'filter'], true)) {
            $sortBy = 'ordered';
        }

        $vendorFilters = [];
        foreach ($vendors as $v) {
            $t = trim((string) $v);
            if ($t !== '') {
                $vendorFilters[] = $t;
            }
        }
        $vendorFilters = array_values(array_unique($vendorFilters));

        $statusFilters = [];
        foreach ($statuses as $status) {
            $next = trim(strtolower((string) $status));
            if (in_array($next, ['draft', 'ordered', 'shipped', 'received', 'on_shelves'], true)) {
                $statusFilters[] = $next;
            }
        }
        $statusFilters = array_values(array_unique($statusFilters));

        $q = PurchaseOrder::query()->withCount('items');

        if ($vendorFilters !== []) {
            $q->whereIn('vendor', $vendorFilters);
        }
        if ($statusFilters !== []) {
            $q->where(function (Builder $sub) use ($statusFilters): void {
                foreach ($statusFilters as $status) {
                    if ($status === 'on_shelves') {
                        $sub->orWhereNotNull('fully_on_shelves_date');

                        continue;
                    }
                    if ($status === 'received') {
                        $sub->orWhere(function (Builder $rx): void {
                            $rx->whereNull('fully_on_shelves_date')
                                ->whereNotNull('received_date');
                        });

                        continue;
                    }
                    if ($status === 'shipped') {
                        $sub->orWhere(function (Builder $sx): void {
                            $sx->whereNull('fully_on_shelves_date')
                                ->whereNull('received_date')
                                ->whereNotNull('shipped_date');
                        });

                        continue;
                    }
                    if ($status === 'ordered') {
                        $sub->orWhere(function (Builder $ox): void {
                            $ox->whereNull('fully_on_shelves_date')
                                ->whereNull('received_date')
                                ->whereNull('shipped_date')
                                ->whereNotNull('ordered_date');
                        });

                        continue;
                    }
                    if ($status === 'draft') {
                        $sub->orWhere(function (Builder $dx): void {
                            $dx->whereNull('fully_on_shelves_date')
                                ->whereNull('received_date')
                                ->whereNull('shipped_date')
                                ->whereNull('ordered_date');
                        });
                    }
                }
            });
        }

        if ($sortBy === 'filter') {
            // Product/price-research PO multiselect: not-arrived first (ETA desc, then created desc),
            // then arrived (received_date desc). sort_dir is ignored for this mode.
            $this->applyPurchaseOrderFilterListSort($q);

            return $q->paginate(perPage: $perPage);
        }

        if ($sortBy === 'ordered') {
            // Keep undated orders at the end for both directions.
            $q->orderByRaw('ordered_date is null asc')
                ->orderBy('ordered_date', $sortDir)
                ->orderBy('created_at', 'desc');
        } elseif ($sortBy === 'received') {
            $q->orderByRaw('received_date is null asc')
                ->orderBy('received_date', $sortDir)
                ->orderBy('created_at', 'desc');
        } else {
            $q->orderBy('created_at', $sortDir);
        }

        return $q->paginate(perPage: $perPage);
    }

    /**
     * Not-arrived POs first (ETA desc, null ETA last, then created desc), then arrived (received_date desc).
     *
     * @param  Builder<PurchaseOrder>  $q
     */
    private function applyPurchaseOrderFilterListSort(Builder $q): void
    {
        $q->orderByRaw('(purchase_orders.received_date IS NOT NULL) ASC')
            ->orderByRaw('CASE WHEN purchase_orders.received_date IS NULL THEN purchase_orders.estimated_arrival_date IS NULL ELSE 0 END ASC')
            ->orderByRaw('CASE WHEN purchase_orders.received_date IS NULL THEN purchase_orders.estimated_arrival_date END DESC')
            ->orderByRaw('CASE WHEN purchase_orders.received_date IS NULL THEN purchase_orders.created_at END DESC')
            ->orderByRaw('CASE WHEN purchase_orders.received_date IS NOT NULL THEN purchase_orders.received_date END DESC')
            ->orderBy('purchase_orders.created_at', 'desc');
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
            throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$uuid]);
        }

        return $po;
    }

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, PurchaseOrder>
     */
    public function findManyForCombinedPayment(array $uuids, bool $lockForUpdate = false): Collection
    {
        $query = PurchaseOrder::query()
            ->with(['items.lots', 'combinedPaymentLine'])
            ->whereIn('uuid', $uuids);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var Collection<int, PurchaseOrder> $rows */
        $rows = $query->get()->keyBy('uuid');

        return collect($uuids)
            ->map(static fn (string $uuid): ?PurchaseOrder => $rows->get($uuid))
            ->filter(static fn (?PurchaseOrder $po): bool => $po !== null)
            ->values();
    }

    public function createCombinedPayment(PurchaseOrderCombinedPayment $payment): PurchaseOrderCombinedPayment
    {
        $payment->save();

        return $payment;
    }

    public function createCombinedPaymentLine(PurchaseOrderCombinedPaymentLine $line): PurchaseOrderCombinedPaymentLine
    {
        $line->save();

        return $line;
    }

    public function hasCombinedPayment(int $purchaseOrderId): bool
    {
        return PurchaseOrderCombinedPaymentLine::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->exists();
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
