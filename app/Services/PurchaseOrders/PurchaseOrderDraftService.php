<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Products\ProductRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

final class PurchaseOrderDraftService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly ProductRepository $products,
        private readonly PurchaseOrderProductMetricsService $productMetrics,
    ) {}

    /**
     * @param  array<int, string>  $productUuids
     * @return array{
     *   purchase_order_uuid:string,
     *   added:int,
     *   skipped_existing:int,
     *   skipped_vendor_mismatch:int
     * }
     */
    public function createDraftFromProductUuids(array $productUuids): array
    {
        $list = $this->products->findByUuids($productUuids)->values();
        if ($list->isEmpty()) {
            throw new RuntimeException('No products found for the provided ids.');
        }

        $vendor = $this->resolveSingleVendor($list);
        if ($vendor === null) {
            throw new RuntimeException('Selected products must all share the same non-empty vendor.');
        }

        $po = new PurchaseOrder;
        $po->uuid = (string) Str::uuid();
        $po->vendor = $vendor;
        $po->vendor_currency_code = 'CAD';
        $po->ordered_date = null;
        $po->shipped_date = null;
        $po->estimated_arrival_date = null;
        $po->received_date = null;
        $po->fully_on_shelves_date = null;
        $po->shipping_total = null;
        $po->surcharge_total = null;
        $po->product_total = null;
        $po->vendor_product_total = null;
        $po->fx_rate_to_cad = null;
        $po->notes = null;
        $po->is_done = false;

        $po = $this->purchaseOrders->create($po);
        $result = $this->addProductsToPurchaseOrder($po, $list);

        return [
            'purchase_order_uuid' => $po->uuid,
            'added' => $result['added'],
            'skipped_existing' => $result['skipped_existing'],
            'skipped_vendor_mismatch' => $result['skipped_vendor_mismatch'],
        ];
    }

    /**
     * @param  array<int, string>  $skus
     * @return array{
     *   purchase_order_uuid:string,
     *   requested_skus:int,
     *   found_products:int,
     *   added:int,
     *   skipped_existing:int,
     *   skipped_vendor_mismatch:int,
     *   skipped_not_found:int
     * }
     */
    public function addProductsBySkus(string $purchaseOrderUuid, array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map(
            static fn (string $sku): string => trim($sku),
            $skus,
        ), static fn (string $sku): bool => $sku !== '')));

        $po = $this->purchaseOrders->findByUuidOrFail($purchaseOrderUuid);
        $products = $this->products->findBySkus($skus)->values();
        $foundSkuMap = $products
            ->pluck('id', 'sku')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missing = 0;
        foreach ($skus as $sku) {
            if (! array_key_exists($sku, $foundSkuMap)) {
                $missing++;
            }
        }

        $result = $this->addProductsToPurchaseOrder($po, $products);

        return [
            'purchase_order_uuid' => $po->uuid,
            'requested_skus' => count($skus),
            'found_products' => $products->count(),
            'added' => $result['added'],
            'skipped_existing' => $result['skipped_existing'],
            'skipped_vendor_mismatch' => $result['skipped_vendor_mismatch'],
            'skipped_not_found' => $missing,
        ];
    }

    /**
     * @return array{
     *   added:int,
     *   skipped_existing:int,
     *   skipped_vendor_mismatch:int
     * }
     */
    private function addProductsToPurchaseOrder(PurchaseOrder $po, Collection $products): array
    {
        if (! $po->relationLoaded('items')) {
            $po->load('items');
        }

        $existingProductIds = [];
        foreach ($po->items as $item) {
            if ($item->product_id !== null) {
                $existingProductIds[(int) $item->product_id] = true;
            }
        }

        /** @var array<int, Product> $uniqueProducts */
        $uniqueProducts = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $uniqueProducts[(int) $product->id] = $product;
        }

        $metricsByProductId = $this->productMetrics->metricsByProductIds(array_keys($uniqueProducts));
        $added = 0;
        $skippedExisting = 0;
        $skippedVendorMismatch = 0;

        foreach ($uniqueProducts as $productId => $product) {
            if (isset($existingProductIds[$productId])) {
                $skippedExisting++;

                continue;
            }

            $productVendor = trim((string) ($product->vendor ?? ''));
            if ($productVendor === '' || strcasecmp($productVendor, $po->vendor) !== 0) {
                $skippedVendorMismatch++;

                continue;
            }

            $metrics = $metricsByProductId[$productId] ?? null;
            $qtyOrdered = is_array($metrics) ? (int) ($metrics['reorder'] ?? 0) : 0;
            $latestLanded = is_array($metrics) ? ($metrics['latest_landed_unit_cost'] ?? null) : null;

            $item = new PurchaseOrderItem;
            $item->purchase_order_id = $po->id;
            $item->product_id = $product->id;
            $item->sku = $product->sku;
            $item->vendor = $po->vendor;
            $item->unit_cost = $latestLanded;
            $item->qty_ordered = $qtyOrdered;
            $item->qty_shipped = null;
            $item->qty_received = null;
            $this->purchaseOrders->createItem($item);
            $existingProductIds[$productId] = true;
            $added++;
        }

        return [
            'added' => $added,
            'skipped_existing' => $skippedExisting,
            'skipped_vendor_mismatch' => $skippedVendorMismatch,
        ];
    }

    private function resolveSingleVendor(Collection $products): ?string
    {
        $vendor = null;
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $next = trim((string) ($product->vendor ?? ''));
            if ($next === '') {
                return null;
            }
            if ($vendor === null) {
                $vendor = $next;

                continue;
            }
            if (strcasecmp($vendor, $next) !== 0) {
                return null;
            }
        }

        return $vendor;
    }
}
