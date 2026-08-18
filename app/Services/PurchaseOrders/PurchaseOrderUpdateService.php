<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Inventory\InventoryRepository;
use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\InventoryLot;
use App\Models\PurchaseOrder;
use App\Services\Products\ProductLatestCostCacheService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderUpdateService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
        private readonly InventoryRepository $inventory,
        private readonly ProductLatestCostCacheService $latestCosts,
        private readonly PurchaseOrderShipmentMethodService $shipmentMethods,
    ) {}

    /**
     * @param  array{
     *   vendor?:string,
     *   supplier_order_id?:string|null,
     *   ordered_date?:string|null,
     *   shipped_date?:string|null,
     *   estimated_arrival_date?:string|null,
     *   received_date?:string|null,
     *   fully_on_shelves_date?:string|null,
     *   shipping_total?:string|null,
     *   surcharge_total?:string|null,
     *   product_total?:string|null,
     *   vendor_currency_code?:string,
     *   vendor_product_total?:string|null,
     *   vendor_shipping_total?:string|null,
     *   notes?:string|null,
     *   is_done?:bool,
     *   exclude_from_latest_arrivals_ordering?:bool,
     *   shipment_method?:string|null,
     *   shipment_tracking_numbers?:array<int, string>
     * } $changes
     */
    public function update(string $uuid, array $changes): PurchaseOrder
    {
        return DB::transaction(function () use ($uuid, $changes): PurchaseOrder {
            $po = $this->purchaseOrders->findByUuidOrFail($uuid);

            $beforeShipping = $po->shipping_total !== null ? trim((string) $po->shipping_total) : null;
            $beforeReceivedDate = $po->received_date?->toDateString();
            $beforeShippedDate = $po->shipped_date?->toDateString();
            $beforeOrderedDate = $po->ordered_date?->toDateString();

            if (array_key_exists('vendor', $changes)) {
                $po->vendor = trim((string) $changes['vendor']);
            }
            if (array_key_exists('supplier_order_id', $changes)) {
                $nextSupplierOrderId = trim((string) ($changes['supplier_order_id'] ?? ''));
                $po->supplier_order_id = $nextSupplierOrderId !== '' ? $nextSupplierOrderId : null;
            }
            if (array_key_exists('ordered_date', $changes)) {
                $po->ordered_date = $changes['ordered_date'];
            }
            if (array_key_exists('shipped_date', $changes)) {
                $po->shipped_date = $changes['shipped_date'];
            }
            if (array_key_exists('estimated_arrival_date', $changes)) {
                $po->estimated_arrival_date = $changes['estimated_arrival_date'];
            }
            if (array_key_exists('received_date', $changes)) {
                $po->received_date = $changes['received_date'];
            }
            if (array_key_exists('fully_on_shelves_date', $changes)) {
                $po->fully_on_shelves_date = $changes['fully_on_shelves_date'];
            }
            if (array_key_exists('shipping_total', $changes)) {
                $po->shipping_total = $changes['shipping_total'];
            }
            if (array_key_exists('surcharge_total', $changes)) {
                $po->surcharge_total = $changes['surcharge_total'];
            }
            if (array_key_exists('product_total', $changes)) {
                $po->product_total = $changes['product_total'];
            }
            if (array_key_exists('vendor_currency_code', $changes)) {
                $po->vendor_currency_code = strtoupper(trim((string) $changes['vendor_currency_code']));
            }
            if (array_key_exists('vendor_product_total', $changes)) {
                $po->vendor_product_total = $changes['vendor_product_total'];
            }
            if (array_key_exists('vendor_shipping_total', $changes)) {
                $po->vendor_shipping_total = $changes['vendor_shipping_total'];
            }
            if (array_key_exists('notes', $changes)) {
                $po->notes = $changes['notes'];
            }
            if (array_key_exists('is_done', $changes)) {
                $po->is_done = (bool) $changes['is_done'];
            }
            if (array_key_exists('exclude_from_latest_arrivals_ordering', $changes)) {
                $po->exclude_from_latest_arrivals_ordering = (bool) $changes['exclude_from_latest_arrivals_ordering'];
            }
            if (array_key_exists('shipment_method', $changes)) {
                $po->shipment_method = $this->shipmentMethods->normalize(
                    $changes['shipment_method'] !== null ? (string) $changes['shipment_method'] : null,
                );
            }
            if (array_key_exists('shipment_tracking_numbers', $changes)) {
                $po->shipment_tracking_numbers = $this->normalizeTrackingNumbers(
                    $changes['shipment_tracking_numbers'],
                );
            }

            $po->fx_rate_to_cad = $this->deriveFxRateToCad(
                $po->product_total !== null ? (string) $po->product_total : null,
                $po->vendor_product_total !== null ? (string) $po->vendor_product_total : null,
                (string) $po->vendor_currency_code,
            );

            $this->purchaseOrders->save($po);

            $this->maybeConvertItemCostsToCad($po);

            $afterShipping = $po->shipping_total !== null ? trim((string) $po->shipping_total) : null;
            $afterReceivedDate = $po->received_date?->toDateString();
            $afterShippedDate = $po->shipped_date?->toDateString();
            $afterOrderedDate = $po->ordered_date?->toDateString();

            $shouldRecalc = $beforeShipping !== $afterShipping
                || $beforeReceivedDate !== $afterReceivedDate
                || $beforeShippedDate !== $afterShippedDate
                || $beforeOrderedDate !== $afterOrderedDate;

            if ($shouldRecalc) {
                $this->recomputeLotsForPo($po);
            }

            $po->loadMissing('items');
            $this->latestCosts->recomputeForSkus($po->items->pluck('sku')->all());

            return $po;
        });
    }

    /** @return array<int, string>|null */
    private function normalizeTrackingNumbers(mixed $numbers): ?array
    {
        if (! is_array($numbers)) {
            return null;
        }

        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (mixed $number): string => trim((string) $number), $numbers),
            static fn (string $number): bool => $number !== '',
        )));

        return $normalized !== [] ? $normalized : null;
    }

    private function recomputeLotsForPo(PurchaseOrder $po): void
    {
        $itemIds = $po->items->pluck('id')->all();
        if ($itemIds === []) {
            return;
        }

        $totalReceived = 0;
        foreach ($po->items as $item) {
            $qty = (int) ($item->qty_received ?? 0);
            if ($qty > 0) {
                $totalReceived += $qty;
            }
        }

        $shippingPerUnit = null;
        $shippingTotal = $po->shipping_total !== null ? trim((string) $po->shipping_total) : null;
        if ($shippingTotal !== null && $shippingTotal !== '' && $totalReceived > 0) {
            $shippingPerUnit = $this->divideDecimal($shippingTotal, $totalReceived, 6);
        }

        $receivedAt = $this->resolveReceivedAt(
            $po->received_date?->toDateString(),
            $po->shipped_date?->toDateString(),
            $po->ordered_date?->toDateString(),
        );

        InventoryLot::query()
            ->whereIn('purchase_order_item_id', $itemIds)
            ->where('source_type', '=', 'po')
            ->update([
                'shipping_per_unit' => $shippingPerUnit,
                'received_at' => $receivedAt,
                'updated_at' => now(),
            ]);
    }

    private function resolveReceivedAt(?string $receivedDate, ?string $shippedDate, ?string $orderedDate): \DateTimeInterface
    {
        $candidate = $receivedDate ?? $shippedDate ?? $orderedDate;
        if ($candidate === null || trim($candidate) === '') {
            return now();
        }

        return CarbonImmutable::parse($candidate)->startOfDay();
    }

    private function divideDecimal(string $numerator, int $denominator, int $scale): string
    {
        $num = trim($numerator);
        if ($num === '') {
            return '0';
        }
        if (! extension_loaded('bcmath')) {
            $value = ((float) $num) / max(1, $denominator);

            return number_format($value, $scale, '.', '');
        }

        /** @var string $out */
        $out = bcdiv($num, (string) max(1, $denominator), $scale);

        return $out;
    }

    private function deriveFxRateToCad(?string $productTotalCad, ?string $vendorProductTotal, string $vendorCurrencyCode): ?string
    {
        $currency = strtoupper(trim($vendorCurrencyCode));
        if ($currency === '' || $currency === 'CAD') {
            return null;
        }

        $cad = $productTotalCad !== null ? trim($productTotalCad) : '';
        $vendor = $vendorProductTotal !== null ? trim($vendorProductTotal) : '';
        if ($cad === '' || $vendor === '') {
            return null;
        }
        if (! is_numeric($cad) || ! is_numeric($vendor)) {
            return null;
        }
        if ((float) $vendor <= 0) {
            return null;
        }

        if (! extension_loaded('bcmath')) {
            $rate = ((float) $cad) / ((float) $vendor);

            return number_format($rate, 6, '.', '');
        }

        /** @var string $out */
        $out = bcdiv($cad, $vendor, 6);

        return $out;
    }

    private function maybeConvertItemCostsToCad(PurchaseOrder $po): void
    {
        $currency = strtoupper(trim((string) $po->vendor_currency_code));
        $fx = $po->fx_rate_to_cad !== null ? (string) $po->fx_rate_to_cad : null;
        if ($currency === '' || $currency === 'CAD' || $fx === null || ! is_numeric($fx) || (float) $fx <= 0) {
            return;
        }

        if (! $po->relationLoaded('items')) {
            $po->load('items');
        }

        foreach ($po->items as $item) {
            // If we don't have a vendor_unit_cost yet, assume the existing unit_cost was the vendor currency.
            if ($item->vendor_unit_cost === null && $item->unit_cost !== null) {
                $item->vendor_unit_cost = $item->unit_cost;
            }

            if ($item->vendor_unit_cost === null) {
                continue;
            }

            $vendor = trim((string) $item->vendor_unit_cost);
            if ($vendor === '' || ! is_numeric($vendor)) {
                continue;
            }

            // Keep 2dp to match PO line display and manual edits.
            $item->unit_cost = $this->mulDecimalRounded($vendor, $fx, 2);
            $item->save();
        }
    }

    private function mulDecimalRounded(string $a, string $b, int $scale): string
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === '' || $b === '' || ! is_numeric($a) || ! is_numeric($b)) {
            return number_format(0, $scale, '.', '');
        }

        if (extension_loaded('bcmath')) {
            $extra = $scale + 2;
            /** @var string $raw */
            $raw = bcmul($a, $b, $extra);

            $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'5';
            $adjusted = str_starts_with($raw, '-')
                ? bcsub($raw, $increment, $extra)
                : bcadd($raw, $increment, $extra);

            /** @var string $out */
            $out = bcadd($adjusted, '0', $scale);

            return $out;
        }

        $value = round(((float) $a) * ((float) $b), $scale);

        return number_format($value, $scale, '.', '');
    }
}
