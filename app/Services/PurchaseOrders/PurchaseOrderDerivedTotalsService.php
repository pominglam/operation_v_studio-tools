<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\InventoryLot;
use App\Models\PurchaseOrder;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class PurchaseOrderDerivedTotalsService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    public function recompute(PurchaseOrder $po): ?string
    {
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

        $totalReceived = 0;
        $productTotalCents = 0;
        $hasCadUnitCosts = false;
        foreach ($items as $item) {
            $qtyReceived = (int) ($item->qty_received ?? 0);
            if ($qtyReceived > 0) {
                $totalReceived += $qtyReceived;
            }

            $qtyForProductTotal = $qtyReceived > 0 ? $qtyReceived : (int) ($item->qty_ordered ?? 0);
            if ($qtyForProductTotal <= 0) {
                continue;
            }

            $unitCostCents = $item->unit_cost !== null ? $this->moneyToCentsOrNull((string) $item->unit_cost) : null;
            if ($unitCostCents === null) {
                continue;
            }

            $hasCadUnitCosts = true;
            $productTotalCents += ($unitCostCents * $qtyForProductTotal);
        }

        $shippingPerUnit = $this->applyShippingToLots($po, $items, $totalReceived);

        if ($hasCadUnitCosts) {
            $po->product_total = $this->centsToMoney($productTotalCents);
        }
        $this->purchaseOrders->save($po);

        return $shippingPerUnit;
    }

    public function recomputeShippingLotsOnly(PurchaseOrder $po): ?string
    {
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);
        $totalReceived = 0;
        foreach ($items as $item) {
            $qtyReceived = (int) ($item->qty_received ?? 0);
            if ($qtyReceived > 0) {
                $totalReceived += $qtyReceived;
            }
        }

        return $this->applyShippingToLots($po, $items, $totalReceived);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\PurchaseOrderItem>  $items
     */
    private function applyShippingToLots(PurchaseOrder $po, $items, int $totalReceived): ?string
    {
        $shippingPerUnit = null;
        $shippingTotal = $po->shipping_total !== null ? trim((string) $po->shipping_total) : null;
        if ($shippingTotal !== null && $shippingTotal !== '' && $totalReceived > 0) {
            $shippingPerUnit = $this->divideDecimal($shippingTotal, $totalReceived, 6);
        }

        $itemIds = $items->pluck('id')->all();
        if ($itemIds !== []) {
            InventoryLot::query()
                ->whereIn('purchase_order_item_id', $itemIds)
                ->where('source_type', '=', 'po')
                ->update([
                    'shipping_per_unit' => $shippingPerUnit,
                    'received_at' => $this->resolveReceivedAt(
                        $po->received_date,
                        $po->shipped_date,
                        $po->ordered_date,
                    ),
                    'updated_at' => now(),
                ]);
        }

        return $shippingPerUnit;
    }

    private function resolveReceivedAt(string|DateTimeInterface|null $receivedDate, string|DateTimeInterface|null $shippedDate, string|DateTimeInterface|null $orderedDate): DateTimeInterface
    {
        $candidate = $receivedDate ?? $shippedDate ?? $orderedDate;
        if ($candidate instanceof DateTimeInterface) {
            return CarbonImmutable::instance($candidate)->startOfDay();
        }

        if ($candidate === null || trim($candidate) === '') {
            return now();
        }

        return CarbonImmutable::parse($candidate)->startOfDay();
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
}
