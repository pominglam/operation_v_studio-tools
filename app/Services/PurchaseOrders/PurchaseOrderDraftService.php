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
        private readonly PurchaseOrderShipmentMethodService $shipmentMethods,
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
        $po->shipment_method = $this->shipmentMethods->inferFromProducts($list);

        $po = $this->purchaseOrders->create($po);
        $result = $this->addProductsToPurchaseOrder($po, $list);
        if ($result['added'] > 0) {
            $this->syncHeaderTotalsFromLines($po);
            $this->shipmentMethods->applyInferredFromLineItemsIfUnset($po);
        }

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
        if ($result['added'] > 0) {
            $this->syncHeaderTotalsFromLines($po);
            $this->shipmentMethods->applyInferredFromLineItemsIfUnset($po);
        }

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

            $item = new PurchaseOrderItem;
            $item->purchase_order_id = $po->id;
            $item->product_id = $product->id;
            $item->sku = $product->sku;
            $item->vendor = $po->vendor;
            $item->unit_cost = $this->resolveDraftLineUnitCost($product);
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

    private function resolveDraftLineUnitCost(Product $product): ?string
    {
        $unit = $this->money2($product->latest_unit_cost);
        if ($unit !== null) {
            return $unit;
        }

        return $this->money2($product->latest_landed_unit_cost);
    }

    private function syncHeaderTotalsFromLines(PurchaseOrder $po): void
    {
        $items = $this->purchaseOrders->itemsForPurchaseOrderId($po->id);
        $productIds = [];
        foreach ($items as $item) {
            if ($item->product_id !== null) {
                $productIds[] = (int) $item->product_id;
            }
        }

        /** @var \Illuminate\Support\Collection<int, Product> $productsById */
        $productsById = $productIds === []
            ? collect()
            : Product::query()->whereIn('id', array_values(array_unique($productIds)))->get()->keyBy('id');

        $productTotalCents = 0;
        $shippingTotalCents = 0;
        $hasPricedLine = false;

        foreach ($items as $item) {
            $qty = max(0, (int) ($item->qty_ordered ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $unitCents = $item->unit_cost !== null
                ? $this->moneyToCentsOrNull((string) $item->unit_cost)
                : null;
            if ($unitCents !== null) {
                $productTotalCents += $unitCents * $qty;
                $hasPricedLine = true;
            }

            $product = $item->product_id !== null
                ? $productsById->get((int) $item->product_id)
                : null;
            if (! $product instanceof Product) {
                continue;
            }

            $landedCents = $this->moneyToCentsOrNull((string) ($product->latest_landed_unit_cost ?? ''));
            $cachedUnitCents = $this->moneyToCentsOrNull((string) ($product->latest_unit_cost ?? ''));
            $basisUnitCents = $unitCents ?? $cachedUnitCents;
            if ($landedCents !== null && $basisUnitCents !== null && $landedCents > $basisUnitCents) {
                $shippingTotalCents += ($landedCents - $basisUnitCents) * $qty;
            }
        }

        if (! $hasPricedLine) {
            return;
        }

        $po->product_total = $this->centsToMoney($productTotalCents);
        $po->shipping_total = $this->centsToMoney($shippingTotalCents);
        $this->purchaseOrders->save($po);
    }

    private function money2(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    private function moneyToCentsOrNull(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || $clean === '-' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        $negative = str_starts_with($clean, '-');
        $raw = $negative ? substr($clean, 1) : $clean;
        $parts = explode('.', $raw, 2);
        $whole = $parts[0] === '' ? '0' : $parts[0];
        $fraction = str_pad((string) ($parts[1] ?? ''), 3, '0');
        $cents2 = substr($fraction, 0, 2);
        $third = (int) substr($fraction, 2, 1);

        $cents = ((int) $whole) * 100 + ((int) $cents2);
        if ($third >= 5) {
            $cents += 1;
        }

        return $negative ? -$cents : $cents;
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $dollars = intdiv($abs, 100);
        $remainder = $abs % 100;

        return sprintf('%s%d.%02d', $negative ? '-' : '', $dollars, $remainder);
    }
}
