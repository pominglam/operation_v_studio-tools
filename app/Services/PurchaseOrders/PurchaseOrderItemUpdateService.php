<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductLatestCostCacheService;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderItemUpdateException;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderItemUpdateService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
        private readonly ProductLatestCostCacheService $latestCosts,
        private readonly ProductRepository $products,
    ) {}

    public function updateItem(
        int $purchaseOrderItemId,
        bool $hasUnitCost,
        ?string $unitCost,
        bool $hasQtyOrdered,
        ?int $qtyOrdered,
        bool $hasQtyShipped,
        ?int $qtyShipped,
        bool $hasQtyReceived,
        ?int $qtyReceived,
        bool $hasQtyDamaged,
        int $qtyDamaged,
    ): PurchaseOrderItem {
        return DB::transaction(function () use ($purchaseOrderItemId, $hasUnitCost, $unitCost, $hasQtyOrdered, $qtyOrdered, $hasQtyShipped, $qtyShipped, $hasQtyReceived, $qtyReceived, $hasQtyDamaged, $qtyDamaged): PurchaseOrderItem {
            $item = $this->purchaseOrders->findItemByIdOrFail($purchaseOrderItemId);
            $item->loadMissing('purchaseOrder');

            $issues = [];
            $ordered = $hasQtyOrdered ? $qtyOrdered : $item->qty_ordered;
            $shipped = $hasQtyShipped ? $qtyShipped : $item->qty_shipped;
            $received = $hasQtyReceived ? $qtyReceived : $item->qty_received;
            $damaged = $hasQtyDamaged ? $qtyDamaged : (int) $item->qty_damaged;

            if ($hasQtyOrdered && $qtyOrdered !== null) {
                if ($shipped !== null && $shipped > $qtyOrdered) {
                    $issues[] = [
                        'kind' => 'qty_shipped_exceeds_ordered',
                        'purchase_order_item_id' => $purchaseOrderItemId,
                        'qty_shipped' => $shipped,
                        'qty_ordered' => $qtyOrdered,
                    ];
                }

            }

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

            if ($hasQtyReceived || $hasQtyDamaged) {
                $hasLots = $this->inventory->countLotsForPurchaseOrderItems([$item->id]) > 0;
                if ($hasLots) {
                    throw new PurchaseOrderItemUpdateException('Cannot edit received or damaged quantities when inventory lots exist for this item.', [
                        [
                            'kind' => 'receipt_quantities_have_lots',
                            'purchase_order_item_id' => $purchaseOrderItemId,
                        ],
                    ]);
                }
            }

            if ($damaged > (int) ($received ?? 0)) {
                $issues[] = [
                    'kind' => 'qty_damaged_exceeds_received',
                    'purchase_order_item_id' => $purchaseOrderItemId,
                    'qty_damaged' => $damaged,
                    'qty_received' => $received,
                ];
            }

            if ($hasQtyReceived) {
                $item->qty_received = $qtyReceived;
            }
            if ($hasQtyDamaged) {
                $item->qty_damaged = $qtyDamaged;
            }

            if ($issues !== []) {
                throw new PurchaseOrderItemUpdateException('Update blocked due to validation errors.', $issues);
            }

            if ($hasQtyOrdered) {
                $item->qty_ordered = $qtyOrdered;
            }

            if ($hasUnitCost) {
                $nextUnitCost = $unitCost !== null ? $this->normalizeDecimalRounded($unitCost, 4) : null;
                $po = $item->purchaseOrder;
                $currency = strtoupper(trim((string) ($po?->vendor_currency_code ?? 'CAD')));
                $fxRateToCad = $po?->fx_rate_to_cad !== null ? trim((string) $po->fx_rate_to_cad) : null;

                $item->unit_cost = $nextUnitCost;
                if ($nextUnitCost === null) {
                    $item->vendor_unit_cost = null;
                } elseif (
                    $currency !== ''
                    && $currency !== 'CAD'
                    && $fxRateToCad !== null
                    && is_numeric($fxRateToCad)
                    && (float) $fxRateToCad > 0
                ) {
                    $item->vendor_unit_cost = $this->normalizeDecimalRounded($this->divideDecimalRounded($nextUnitCost, $fxRateToCad, 4), 4);
                } elseif ($currency === '' || $currency === 'CAD') {
                    $item->vendor_unit_cost = null;
                }
            }

            $this->purchaseOrders->saveItem($item);

            $po = $item->purchaseOrder()->with('items')->first();
            if ($po instanceof PurchaseOrder) {
                $this->latestCosts->recomputeForSkus($po->items->pluck('sku')->all());
            }

            return $item->loadMissing('purchaseOrder');
        });
    }

    private function normalizeDecimalRounded(string $value, int $scale): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return number_format(0, $scale, '.', '');
        }

        return number_format((float) $trimmed, $scale, '.', '');
    }

    private function divideDecimalRounded(string $numerator, string $denominator, int $scale): string
    {
        $num = trim($numerator);
        $den = trim($denominator);
        if ($num === '' || $den === '' || ! is_numeric($num) || ! is_numeric($den) || (float) $den <= 0) {
            return number_format(0, $scale, '.', '');
        }

        if (extension_loaded('bcmath')) {
            $extra = $scale + 2;
            /** @var string $raw */
            $raw = bcdiv($num, $den, $extra);
            $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'5';
            $adjusted = str_starts_with($raw, '-')
                ? bcsub($raw, $increment, $extra)
                : bcadd($raw, $increment, $extra);
            /** @var string $out */
            $out = bcadd($adjusted, '0', $scale);

            return $out;
        }

        return number_format(((float) $num) / ((float) $den), $scale, '.', '');
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

                $this->latestCosts->recomputeForSkus($po->items->pluck('sku')->all());

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

                $this->latestCosts->recomputeForSkus($po->items->pluck('sku')->all());

                return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            }

            // Legacy items[] path removed in favor of ids+changes. Keep "no-op" if we get here.
            return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        });
    }

    /**
     * @param  array<int, int>  $itemIds
     * @param  array{qty_shipped?:int|null, qty_received?:int|null, set_shipped_to_ordered?:bool, set_received_to_shipped?:bool, product_vendor?:string|null}  $changes
     */
    public function bulkUpdateSelected(string $purchaseOrderUuid, array $itemIds, array $changes): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $itemIds, $changes): PurchaseOrder {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            $po->loadMissing(['items.product']);

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
            $applyProductVendor = array_key_exists('product_vendor', $changes);
            $productVendor = $applyProductVendor
                ? (is_string($changes['product_vendor'] ?? null) ? trim((string) $changes['product_vendor']) : null)
                : null;
            if ($applyProductVendor && $productVendor === '') {
                $productVendor = null;
            }

            if ($setReceivedToShipped || $applyQtyReceived) {
                $countLots = $this->inventory->countLotsForPurchaseOrderItems($items->pluck('id')->all());
                if ($countLots > 0) {
                    throw new PurchaseOrderItemUpdateException('Cannot bulk edit received quantities when inventory lots exist for one or more selected items.', [
                        ['kind' => 'receipt_quantities_have_lots'],
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
                    $it->qty_received = $qtyReceived;
                }

                if ((int) $it->qty_damaged > (int) ($it->qty_received ?? 0)) {
                    $issues[] = [
                        'kind' => 'qty_damaged_exceeds_received',
                        'purchase_order_item_id' => $it->id,
                        'qty_damaged' => (int) $it->qty_damaged,
                        'qty_received' => $it->qty_received,
                    ];
                }

                $this->purchaseOrders->saveItem($it);
            }

            if ($applyProductVendor) {
                foreach ($items as $it) {
                    $product = $it->product;
                    if ($product === null) {
                        continue;
                    }
                    $product->vendor = $productVendor;
                    $this->products->save($product);
                }
            }

            if ($issues !== []) {
                throw new PurchaseOrderItemUpdateException('Bulk update blocked due to validation errors.', $issues);
            }

            $this->latestCosts->recomputeForSkus($po->items->pluck('sku')->all());

            return $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        });
    }
}
