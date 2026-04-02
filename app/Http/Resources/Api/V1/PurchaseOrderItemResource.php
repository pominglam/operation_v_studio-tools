<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<PurchaseOrderItem> */
final class PurchaseOrderItemResource extends JsonResource
{
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

    private function unitCostCadForItem(PurchaseOrderItem $item): ?string
    {
        $po = $item->purchaseOrder;
        $currency = $po?->vendor_currency_code !== null ? strtoupper(trim((string) $po->vendor_currency_code)) : 'CAD';
        $fx = $po?->fx_rate_to_cad !== null ? (string) $po->fx_rate_to_cad : null;

        if ($currency === '' || $currency === 'CAD') {
            return $item->unit_cost !== null ? (string) $item->unit_cost : null;
        }

        // If FX isn't known, we cannot show CAD.
        if ($fx === null || ! is_numeric($fx) || (float) $fx <= 0) {
            return null;
        }

        // Prefer explicit vendor_unit_cost; fall back to legacy unit_cost (which may still be vendor currency).
        $vendor = $item->vendor_unit_cost !== null ? (string) $item->vendor_unit_cost : ($item->unit_cost !== null ? (string) $item->unit_cost : null);
        if ($vendor === null || trim($vendor) === '' || ! is_numeric($vendor)) {
            return null;
        }

        // 4dp for storage-style precision; money2() will display at 2dp.
        return $this->mulDecimalRounded($vendor, $fx, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrderItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'product_id' => $item->product?->uuid,
            'product_name' => $item->product?->description,
            'product_barcode' => $item->product?->barcode,
            'product_handle' => $item->product?->handle,
            'sku' => $item->sku,
            'vendor' => $item->vendor,
            // Always return CAD unit cost for display/costing; for foreign vendors, uses FX on the parent PO.
            'unit_cost' => $this->money2($this->unitCostCadForItem($item)),
            'vendor_unit_cost' => $this->money2($item->vendor_unit_cost),
            'qty_ordered' => $item->qty_ordered,
            'qty_shipped' => $item->qty_shipped,
            'qty_received' => $item->qty_received,
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }
}
