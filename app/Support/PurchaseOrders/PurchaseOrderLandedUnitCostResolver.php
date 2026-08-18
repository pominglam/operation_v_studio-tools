<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

final class PurchaseOrderLandedUnitCostResolver
{
    public function __construct(
        private readonly PurchaseOrderItemCadUnitCostResolver $cadUnitCosts,
    ) {}

    /**
     * @param  iterable<int, PurchaseOrderItem>  $items
     * @return array<int, string> product_id => landed unit cost (money2)
     */
    public function landedByProductId(PurchaseOrder $po, iterable $items): array
    {
        $itemList = [];
        foreach ($items as $item) {
            if ($item instanceof PurchaseOrderItem) {
                $itemList[] = $item;
            }
        }

        if ($itemList === []) {
            return [];
        }

        $sumReceived = 0;
        $sumOrdered = 0;
        $receivedEntriesCount = 0;
        foreach ($itemList as $item) {
            $sumReceived += (int) ($item->qty_received ?? 0);
            $sumOrdered += (int) ($item->qty_ordered ?? 0);
            if ($item->qty_received !== null) {
                $receivedEntriesCount++;
            }
        }

        $units = PurchaseOrderAllocation::unitsFromTotals($sumReceived, $sumOrdered, $receivedEntriesCount);
        $shipPerUnit = $this->perUnitOrZero($po->shipping_total, $units);
        $surchargePerUnit = $this->perUnitOrZero($po->surcharge_total, $units);
        $shipCents = $this->moneyToCentsOrNull($shipPerUnit) ?? 0;
        $surchargeCents = $this->moneyToCentsOrNull($surchargePerUnit) ?? 0;

        $out = [];
        foreach ($itemList as $item) {
            $productId = $item->product_id !== null ? (int) $item->product_id : 0;
            if ($productId <= 0) {
                continue;
            }

            $unitCents = $this->cadUnitCosts->resolveCents($item, $po);
            if ($unitCents === null) {
                continue;
            }

            $landedCents = $unitCents + $shipCents + $surchargeCents;
            $out[$productId] = $this->money2FromCents($landedCents);
        }

        return $out;
    }

    private function perUnitOrZero(mixed $total, int $units): string
    {
        $cents = $this->moneyToCentsOrNull($total);
        if ($cents === null || $units <= 0) {
            return '0.00';
        }

        $perUnitCents = intdiv($cents + intdiv($units, 2), $units);

        return $this->money2FromCents($perUnitCents);
    }

    private function money2FromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $dollars = intdiv($cents, 100);
        $rem = $cents % 100;

        return $sign.$dollars.'.'.str_pad((string) $rem, 2, '0', STR_PAD_LEFT);
    }

    private function moneyToCentsOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9\.\-]/', '', $s) ?? '';
        if ($clean === '' || $clean === '-' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            return null;
        }

        $neg = str_starts_with($clean, '-');
        if ($neg) {
            $clean = substr($clean, 1);
        }

        [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = $frac ?? '';

        $f = str_pad($frac, 3, '0');
        $centsStr = substr($f, 0, 2);
        $third = (int) ($f[2] ?? '0');

        $cents = ((int) $whole) * 100 + (int) $centsStr;
        if ($third >= 5) {
            $cents++;
        }

        return $neg ? -$cents : $cents;
    }
}
