<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\PurchaseOrders\PmBrokerVendor;

final class PurchaseOrderLinesExportService
{
    private ?string $memoFxRateToCad = null;

    private bool $memoFxResolved = false;

    /**
     * @return array<int, string>
     */
    public function csvHeaders(PurchaseOrder $po): array
    {
        $headers = [
            'SKU',
            'Product Name',
            'Qty',
            'Product cost unit (CAD)',
            'Product cost line (CAD)',
        ];

        if ($this->isHkdBrokerVendor($po->vendor)) {
            $headers[] = 'Product cost unit (HKD)';
            $headers[] = 'Product cost line (HKD)';
        }

        return $headers;
    }

    /**
     * @return array<int, string>|null
     */
    public function csvRow(PurchaseOrder $po, PurchaseOrderItem $item): ?array
    {
        $qty = (int) ($item->qty_ordered ?? 0);
        if ($qty <= 0) {
            return null;
        }

        $qtyStr = (string) $qty;
        $unitCad = $this->productCostUnitCad($po, $item);
        $lineCad = $unitCad !== null
            ? $this->mulDecimalRounded($unitCad, $qtyStr, 2)
            : '';

        $row = [
            (string) $item->sku,
            (string) ($item->product?->description ?? ''),
            $qtyStr,
            $unitCad ?? '',
            $lineCad,
        ];

        if ($this->isHkdBrokerVendor($po->vendor)) {
            $unitHkd = $this->productCostUnitHkd($po, $item);
            $lineHkd = $unitHkd !== null
                ? $this->mulDecimalRounded($unitHkd, $qtyStr, 2)
                : '';
            $row[] = $unitHkd ?? '';
            $row[] = $lineHkd;
        }

        return $row;
    }

    public function suggestedFilename(PurchaseOrder $po): string
    {
        $vendorSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($po->vendor)) ?? 'vendor');
        $vendorSlug = trim($vendorSlug, '-') !== '' ? trim($vendorSlug, '-') : 'vendor';
        $date = now('America/Toronto')->format('Ymd');

        return sprintf('purchase-order-%s-%s-lines.csv', $vendorSlug, $date);
    }

    public function isHkdBrokerVendor(string $vendor): bool
    {
        return PmBrokerVendor::isPmBrokerVendor($vendor);
    }

    private function productCostUnitCad(PurchaseOrder $po, PurchaseOrderItem $item): ?string
    {
        $cad = $this->money2($item->unit_cost);
        if ($cad !== null) {
            return $cad;
        }

        $hkd = $this->money2($item->vendor_unit_cost);
        $fx = $this->fxRateToCadForExport($po);
        if ($hkd !== null && $fx !== null) {
            return $this->mulDecimalRounded($hkd, $fx, 2);
        }

        return null;
    }

    private function productCostUnitHkd(PurchaseOrder $po, PurchaseOrderItem $item): ?string
    {
        $hkd = $this->money2($item->vendor_unit_cost);
        if ($hkd !== null) {
            return $hkd;
        }

        $cad = $this->money2($item->unit_cost);
        $fx = $this->fxRateToCadForExport($po);
        if ($cad !== null && $fx !== null) {
            return $this->divideDecimalRounded($cad, $fx, 2);
        }

        return null;
    }

    private function fxRateToCadForExport(PurchaseOrder $po): ?string
    {
        if ($this->memoFxResolved) {
            return $this->memoFxRateToCad;
        }

        $this->memoFxResolved = true;
        $currency = $this->vendorCurrencyForFxLookup($po);
        if ($currency === 'CAD') {
            $this->memoFxRateToCad = null;

            return null;
        }

        $own = $this->decimal6($po->fx_rate_to_cad);
        if ($own !== null) {
            $this->memoFxRateToCad = $own;

            return $own;
        }

        $prior = PurchaseOrder::query()
            ->where('id', '!=', $po->id)
            ->whereRaw('upper(vendor_currency_code) = ?', [$currency])
            ->whereNotNull('fx_rate_to_cad')
            ->where('fx_rate_to_cad', '>', 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('fx_rate_to_cad');

        $this->memoFxRateToCad = $this->decimal6($prior);

        return $this->memoFxRateToCad;
    }

    private function vendorCurrencyForFxLookup(PurchaseOrder $po): string
    {
        if ($this->isHkdBrokerVendor($po->vendor)) {
            return 'HKD';
        }

        $currency = strtoupper(trim((string) ($po->vendor_currency_code ?? 'CAD')));

        return $currency === '' ? 'CAD' : $currency;
    }

    private function decimal6(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return null;
        }
        if ((float) $trimmed <= 0) {
            return null;
        }

        return number_format((float) $trimmed, 6, '.', '');
    }

    private function money2(mixed $value): ?string
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

        return number_format(round(((float) $a) * ((float) $b), $scale), $scale, '.', '');
    }

    private function divideDecimalRounded(string $a, string $b, int $scale): string
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '' || ! is_numeric($a) || ! is_numeric($b) || (float) $b == 0.0) {
            return number_format(0, $scale, '.', '');
        }

        if (extension_loaded('bcmath')) {
            $extra = $scale + 2;
            /** @var string $raw */
            $raw = bcdiv($a, $b, $extra);
            $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'5';
            $adjusted = str_starts_with($raw, '-')
                ? bcsub($raw, $increment, $extra)
                : bcadd($raw, $increment, $extra);

            /** @var string $out */
            $out = bcadd($adjusted, '0', $scale);

            return $out;
        }

        return number_format(round(((float) $a) / ((float) $b), $scale), $scale, '.', '');
    }
}
