<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\PurchaseOrders\PurchaseOrderRepository;
use App\Models\PurchaseOrder;

final class PurchaseOrderFxRecalculateService
{
    public function __construct(
        private readonly PurchaseOrderRepository $purchaseOrders,
    ) {}

    /**
     * Keeps header product_total (CAD paid) fixed, rolls up vendor_product_total from lines,
     * derives fx_rate_to_cad, and re-converts all line unit_cost values.
     */
    public function recalculateFromFixedProductTotal(PurchaseOrder $po): PurchaseOrder
    {
        $items = $this->purchaseOrders->itemsForPurchaseOrderId((int) $po->id);

        $vendorTotal = '0.00';
        $hasVendorCosts = false;
        foreach ($items as $item) {
            $vendorUnit = $item->vendor_unit_cost !== null ? trim((string) $item->vendor_unit_cost) : '';
            if ($vendorUnit === '' || ! is_numeric($vendorUnit)) {
                continue;
            }

            $qty = (int) ($item->qty_received ?? 0) > 0
                ? (int) $item->qty_received
                : (int) ($item->qty_ordered ?? 0);
            if ($qty <= 0) {
                $qty = 1;
            }

            $hasVendorCosts = true;
            $lineTotal = $this->mulDecimalRounded($vendorUnit, (string) $qty, 2);
            $vendorTotal = $this->addDecimal($vendorTotal, $lineTotal, 2);
        }

        if ($hasVendorCosts) {
            $po->vendor_product_total = $vendorTotal;
        }

        $fx = $this->deriveFxRateToCad(
            $po->product_total !== null ? (string) $po->product_total : null,
            $po->vendor_product_total !== null ? (string) $po->vendor_product_total : null,
            (string) $po->vendor_currency_code,
        );
        if ($fx !== null) {
            $po->fx_rate_to_cad = $fx;
        }

        $this->purchaseOrders->save($po);

        if ($fx !== null) {
            foreach ($items as $item) {
                $vendorUnit = $item->vendor_unit_cost !== null ? trim((string) $item->vendor_unit_cost) : '';
                if ($vendorUnit === '' || ! is_numeric($vendorUnit)) {
                    continue;
                }

                $item->unit_cost = $this->mulDecimalRounded($vendorUnit, $fx, 2);
                $this->purchaseOrders->saveItem($item);
            }
        }

        return $po->fresh() ?? $po;
    }

    private function deriveFxRateToCad(?string $productTotalCad, ?string $vendorProductTotal, string $vendorCurrencyCode): ?string
    {
        $currency = strtoupper(trim($vendorCurrencyCode));
        if ($currency === '' || $currency === 'CAD') {
            return null;
        }

        $cad = $productTotalCad !== null ? trim($productTotalCad) : '';
        $vendor = $vendorProductTotal !== null ? trim($vendorProductTotal) : '';
        if ($cad === '' || $vendor === '' || ! is_numeric($cad) || ! is_numeric($vendor) || (float) $vendor <= 0) {
            return null;
        }

        if (! extension_loaded('bcmath')) {
            return number_format(((float) $cad) / ((float) $vendor), 6, '.', '');
        }

        /** @var string $out */
        $out = bcdiv($cad, $vendor, 6);

        return $out;
    }

    private function addDecimal(string $a, string $b, int $scale): string
    {
        if (! extension_loaded('bcmath')) {
            return number_format(((float) $a) + ((float) $b), $scale, '.', '');
        }

        /** @var string $out */
        $out = bcadd($a, $b, $scale);

        return $out;
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
}
