<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductSellingPriceRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

final class InventoryOpeningBalanceBackfillService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductSellingPriceRepository $sellingPrices,
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
    ) {}

    /**
     * @return array{purchase_orders:int, purchase_order_items:int, inventory_lots:int}
     */
    public function backfill(bool $force = false): array
    {
        $existingLots = (int) DB::table('inventory_lots')->count();
        if ($existingLots > 0 && ! $force) {
            return [
                'purchase_orders' => 0,
                'purchase_order_items' => 0,
                'inventory_lots' => 0,
            ];
        }

        return DB::transaction(function (): array {
            $all = $this->products->listAll();
            $stediSellingSet = array_fill_keys($this->sellingPrices->productIdsWithSellingPriceSet(), true);

            /** @var array<string, array<int, Product>> $groups */
            $groups = [];

            foreach ($all as $p) {
                $vendor = trim((string) ($p->vendor ?? ''));
                if ($vendor === '') {
                    $vendor = 'Unknown';
                }

                if (strcasecmp($vendor, 'Stedi') === 0) {
                    $hasSelling = array_key_exists((int) $p->id, $stediSellingSet);
                    $key = $hasSelling ? 'Stedi-arrived' : 'Stedi-not-arrived';
                    $groups[$key] ??= [];
                    $groups[$key][] = $p;
                    continue;
                }

                if (strcasecmp($vendor, 'Plamod') === 0) {
                    $groups['Plamod'] ??= [];
                    $groups['Plamod'][] = $p;
                    continue;
                }

                $groups[$vendor] ??= [];
                $groups[$vendor][] = $p;
            }

            $poCount = 0;
            $itemCount = 0;
            $lotCount = 0;

            foreach ($groups as $groupKey => $products) {
                if ($products === []) {
                    continue;
                }

                $vendor = str_starts_with($groupKey, 'Stedi') ? 'Stedi' : $groupKey;

                $po = new PurchaseOrder();
                $po->vendor = $vendor;
                $po->shipping_total = '0.00';
                $po->received_date = now()->toDateString();
                $po->notes = "Opening balance backfill ({$groupKey}).";
                $this->purchaseOrders->create($po);
                $poCount++;

                foreach ($products as $p) {
                    $available = (int) ($p->available_qty ?? 0);

                    $item = new PurchaseOrderItem();
                    $item->purchase_order_id = $po->id;
                    $item->product_id = (int) $p->id;
                    $item->sku = (string) $p->sku;
                    $item->vendor = (string) ($p->vendor ?? $vendor);
                    $item->unit_cost = $p->latest_unit_cost;
                    $item->qty_ordered = $available > 0 ? $available : 0;
                    $item->qty_shipped = $available > 0 ? $available : 0;
                    $item->qty_received = $available > 0 ? $available : 0;
                    $this->purchaseOrders->createItem($item);
                    $itemCount++;

                    if ($available <= 0) {
                        continue;
                    }

                    $lot = new InventoryLot();
                    $lot->product_id = (int) $p->id;
                    $lot->purchase_order_item_id = $item->id;
                    $lot->source_type = 'opening_balance';
                    $lot->unit_cost = $p->latest_unit_cost;
                    $lot->shipping_per_unit = '0.000000';
                    $lot->qty_received = $available;
                    $lot->qty_remaining = $available;
                    $lot->received_at = now();
                    $this->inventory->createLot($lot);
                    $lotCount++;
                }
            }

            return [
                'purchase_orders' => $poCount,
                'purchase_order_items' => $itemCount,
                'inventory_lots' => $lotCount,
            ];
        });
    }
}


