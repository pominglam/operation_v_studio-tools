<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Services\Products\ProductLatestCostCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurchaseOrderWaterDecalLineAddService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly PurchaseOrderFxRecalculateService $fxRecalculate,
        private readonly PurchaseOrderDerivedTotalsService $derivedTotals,
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    /**
     * @param  array<int, array{
     *   sku: string,
     *   description: string,
     *   type: string,
     *   vendor_unit_cost_hkd: string,
     *   qty_ordered: int
     * }>  $lines
     * @return array<int, array{sku: string, product_id: int, item_id: int}>
     */
    public function addLines(string $purchaseOrderUuid, array $lines, string $vendor = 'Dspiae'): array
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $lines, $vendor): array {
            $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
            $fx = $po->fx_rate_to_cad !== null ? (string) $po->fx_rate_to_cad : null;

            /** @var array<int, array{sku: string, product_id: int, item_id: int}> $created */
            $created = [];

            foreach ($lines as $line) {
                $sku = trim($line['sku']);
                if ($sku === '') {
                    continue;
                }

                $product = Product::query()->where('sku', '=', $sku)->first();
                if ($product === null) {
                    $product = new Product;
                    $product->uuid = (string) Str::uuid();
                    $product->sku = $sku;
                    $product->description = trim($line['description']);
                    $product->main_type = 'water decals';
                    $product->type = trim($line['type']);
                    $product->vendor = $vendor;
                    $product->save();
                } else {
                    $product->description = trim($line['description']);
                    $product->main_type = 'water decals';
                    $product->type = trim($line['type']);
                    if ($product->vendor === null || trim((string) $product->vendor) === '') {
                        $product->vendor = $vendor;
                    }
                    $product->save();
                }

                $existingItem = PurchaseOrderItem::query()
                    ->where('purchase_order_id', '=', $po->id)
                    ->where('product_id', '=', $product->id)
                    ->first();

                if ($existingItem !== null) {
                    throw new \InvalidArgumentException("PO already has line for SKU {$sku}.");
                }

                $vendorUnitCost = number_format((float) $line['vendor_unit_cost_hkd'], 4, '.', '');
                $unitCost = $fx !== null && is_numeric($fx)
                    ? number_format((float) $vendorUnitCost * (float) $fx, 4, '.', '')
                    : null;

                $item = new PurchaseOrderItem;
                $item->purchase_order_id = $po->id;
                $item->product_id = (int) $product->id;
                $item->sku = $sku;
                $item->vendor = $vendor;
                $item->vendor_unit_cost = $vendorUnitCost;
                $item->unit_cost = $unitCost;
                $item->qty_ordered = max(1, (int) $line['qty_ordered']);
                $this->purchaseOrders->createItem($item);

                $created[] = [
                    'sku' => $sku,
                    'product_id' => (int) $product->id,
                    'item_id' => (int) $item->id,
                ];
            }

            $po = $this->fxRecalculate->recalculateFromFixedProductTotal($po->fresh());
            $this->derivedTotals->recomputeShippingLotsOnly($po);
            $this->latestCosts->recomputeForSkus(array_map(static fn (array $row): string => $row['sku'], $created));

            return $created;
        });
    }
}
