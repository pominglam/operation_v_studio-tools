<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;

final class PurchaseOrderItemCadUnitCostResolver
{
    /**
     * CAD unit cost for PO costing/display — matches PurchaseOrderItemResource `unit_cost`.
     */
    public function resolve(PurchaseOrderItem $item, ?PurchaseOrder $po = null): ?string
    {
        $po ??= $item->relationLoaded('purchaseOrder') ? $item->purchaseOrder : null;
        $currency = $po?->vendor_currency_code !== null
            ? strtoupper(trim((string) $po->vendor_currency_code))
            : 'CAD';
        $fx = $po?->fx_rate_to_cad !== null ? (string) $po->fx_rate_to_cad : null;

        if ($currency === '' || $currency === 'CAD') {
            return $this->money2($item->unit_cost !== null ? (string) $item->unit_cost : null);
        }

        if ($fx === null || ! is_numeric($fx) || (float) $fx <= 0) {
            return null;
        }

        $vendor = $item->vendor_unit_cost !== null
            ? (string) $item->vendor_unit_cost
            : ($item->unit_cost !== null ? (string) $item->unit_cost : null);
        if ($vendor === null || trim($vendor) === '' || ! is_numeric($vendor)) {
            return null;
        }

        return $this->mulDecimalRounded($vendor, $fx, 2);
    }

    public function resolveCents(PurchaseOrderItem $item, ?PurchaseOrder $po = null): ?int
    {
        return $this->moneyToCentsOrNull($this->resolve($item, $po));
    }

    private function money2(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || ! is_numeric($clean)) {
            return $trimmed;
        }

        return number_format((float) $clean, 2, '.', '');
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

    private function moneyToCentsOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $s = trim($value);
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
