<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderItemUpdateException;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderItemUpdateService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
    ) {}

    public function updateItem(int $purchaseOrderItemId, bool $hasQtyShipped, ?int $qtyShipped, bool $hasQtyReceived, ?int $qtyReceived): PurchaseOrderItem
    {
        return DB::transaction(function () use ($purchaseOrderItemId, $hasQtyShipped, $qtyShipped, $hasQtyReceived, $qtyReceived): PurchaseOrderItem {
            $item = $this->purchaseOrders->findItemByIdOrFail($purchaseOrderItemId);

            $issues = [];
            $ordered = $item->qty_ordered;

            if ($hasQtyShipped) {
                if ($ordered !== null && $qtyShipped !== null && $qtyShipped > $ordered) {
                    $issues[] = [
                        'kind' => 'qty_shipped_exceeds_ordered',
                        'purchase_order_item_id' => $purchaseOrderItemId,
                        'qty_shipped' => $qtyShipped,
                        'qty_ordered' => $ordered,
                    ];
                } else {
                    $item->qty_shipped = $qtyShipped;
                }
            }

            if ($hasQtyReceived) {
                $hasLots = $this->inventory->countLotsForPurchaseOrderItems([$item->id]) > 0;
                if ($hasLots) {
                    throw new PurchaseOrderItemUpdateException('Cannot edit qty_received when inventory lots exist for this item.', [
                        [
                            'kind' => 'qty_received_has_lots',
                            'purchase_order_item_id' => $purchaseOrderItemId,
                        ],
                    ]);
                }

                if ($qtyReceived !== null && $ordered !== null && $qtyReceived > $ordered) {
                    $issues[] = [
                        'kind' => 'qty_received_exceeds_ordered',
                        'purchase_order_item_id' => $purchaseOrderItemId,
                        'qty_received' => $qtyReceived,
                        'qty_ordered' => $ordered,
                    ];
                }
                $shipped = $hasQtyShipped ? $qtyShipped : $item->qty_shipped;
                if ($qtyReceived !== null && $shipped !== null && $qtyReceived > $shipped) {
                    $issues[] = [
                        'kind' => 'qty_received_exceeds_shipped',
                        'purchase_order_item_id' => $purchaseOrderItemId,
                        'qty_received' => $qtyReceived,
                        'qty_shipped' => $shipped,
                    ];
                }

                if ($issues === []) {
                    $item->qty_received = $qtyReceived;
                }
            }

            if ($issues !== []) {
                throw new PurchaseOrderItemUpdateException('Update blocked due to validation errors.', $issues);
            }

            $this->purchaseOrders->saveItem($item);

            return $item->loadMissing('purchaseOrder');
        });
    }

    /**
     * @param  array<int, array{id:int, qty_shipped:int|null}>  $items
     */
    public function bulkUpdateQtyShipped(
        string $purchaseOrderUuid,
        ?int $qtyShippedAll = null,
        bool $setAllToOrdered = false,
        array $items = [],
    ): PurchaseOrder {
        return DB::transaction(function () use ($purchaseOrderUuid, $qtyShippedAll, $setAllToOrdered, $items): PurchaseOrder {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            if (! $po->relationLoaded('items')) {
                $po->load('items');
            }

            $issues = [];

            if ($setAllToOrdered) {
                foreach ($po->items as $it) {
                    $it->qty_shipped = $it->qty_ordered;
                    $this->purchaseOrders->saveItem($it);
                }

                return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            }

            if ($qtyShippedAll !== null || array_key_exists('qty_shipped_all', compact('qtyShippedAll'))) {
                foreach ($po->items as $it) {
                    $ordered = $it->qty_ordered;
                    if ($qtyShippedAll !== null && $ordered !== null && $qtyShippedAll > $ordered) {
                        $issues[] = [
                            'kind' => 'qty_shipped_exceeds_ordered',
                            'purchase_order_item_id' => $it->id,
                            'qty_shipped' => $qtyShippedAll,
                            'qty_ordered' => $ordered,
                        ];
                        continue;
                    }
                    $it->qty_shipped = $qtyShippedAll;
                    $this->purchaseOrders->saveItem($it);
                }

                if ($issues !== []) {
                    throw new PurchaseOrderItemUpdateException('Bulk update blocked due to validation errors.', $issues);
                }

                return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            }

            // Legacy items[] path removed in favor of ids+changes. Keep "no-op" if we get here.
            return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        });
    }

    /**
     * @param  array<int, int>  $itemIds
     * @param  array{qty_shipped?:int|null, qty_received?:int|null, set_shipped_to_ordered?:bool, set_received_to_shipped?:bool}  $changes
     */
    public function bulkUpdateSelected(string $purchaseOrderUuid, array $itemIds, array $changes): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $itemIds, $changes): PurchaseOrder {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            $po->loadMissing('items');

            $allowed = array_flip($itemIds);
            $items = $po->items->filter(fn (PurchaseOrderItem $it): bool => isset($allowed[$it->id]))->values();

            if ($items->isEmpty()) {
                throw new PurchaseOrderItemUpdateException('No items selected.', []);
            }

            $issues = [];

            $setShippedToOrdered = (bool) ($changes['set_shipped_to_ordered'] ?? false);
            $setReceivedToShipped = (bool) ($changes['set_received_to_shipped'] ?? false);
            $qtyShipped = array_key_exists('qty_shipped', $changes) ? $changes['qty_shipped'] : null;
            $qtyReceived = array_key_exists('qty_received', $changes) ? $changes['qty_received'] : null;
            $applyQtyShipped = array_key_exists('qty_shipped', $changes);
            $applyQtyReceived = array_key_exists('qty_received', $changes);

            if ($applyQtyReceived) {
                $countLots = $this->inventory->countLotsForPurchaseOrderItems($items->pluck('id')->all());
                if ($countLots > 0) {
                    throw new PurchaseOrderItemUpdateException('Cannot bulk edit qty_received when inventory lots exist for one or more selected items.', [
                        ['kind' => 'qty_received_has_lots'],
                    ]);
                }
            }

            foreach ($items as $it) {
                $ordered = $it->qty_ordered;

                if ($setShippedToOrdered) {
                    $it->qty_shipped = $it->qty_ordered;
                } elseif ($applyQtyShipped) {
                    if ($qtyShipped !== null && $ordered !== null && $qtyShipped > $ordered) {
                        $issues[] = [
                            'kind' => 'qty_shipped_exceeds_ordered',
                            'purchase_order_item_id' => $it->id,
                            'qty_shipped' => $qtyShipped,
                            'qty_ordered' => $ordered,
                        ];
                    } else {
                        $it->qty_shipped = $qtyShipped;
                    }
                }

                if ($setReceivedToShipped) {
                    $it->qty_received = $it->qty_shipped;
                } elseif ($applyQtyReceived) {
                    if ($qtyReceived !== null && $ordered !== null && $qtyReceived > $ordered) {
                        $issues[] = [
                            'kind' => 'qty_received_exceeds_ordered',
                            'purchase_order_item_id' => $it->id,
                            'qty_received' => $qtyReceived,
                            'qty_ordered' => $ordered,
                        ];
                    }
                    $shipped = $it->qty_shipped;
                    if ($qtyReceived !== null && $shipped !== null && $qtyReceived > $shipped) {
                        $issues[] = [
                            'kind' => 'qty_received_exceeds_shipped',
                            'purchase_order_item_id' => $it->id,
                            'qty_received' => $qtyReceived,
                            'qty_shipped' => $shipped,
                        ];
                    }

                    if ($issues === []) {
                        $it->qty_received = $qtyReceived;
                    }
                }

                $this->purchaseOrders->saveItem($it);
            }

            if ($issues !== []) {
                throw new PurchaseOrderItemUpdateException('Bulk update blocked due to validation errors.', $issues);
            }

            return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        });
    }
}

