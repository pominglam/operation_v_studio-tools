<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrderItem;
use App\Support\PurchaseOrders\PurchaseOrderItemCadUnitCostResolver;
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

    private function unitCostCadForItem(PurchaseOrderItem $item): ?string
    {
        return app(PurchaseOrderItemCadUnitCostResolver::class)->resolve($item, $item->purchaseOrder);
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
            'product_name' => $item->product?->description ?? $item->product_name,
            'product_barcode' => $item->product?->barcode ?? $item->barcode,
            'product_vendor' => $this->nullableTrimmedString($item->product?->vendor),
            'product_vendor_missing' => $this->isProductVendorMissing($item),
            'is_catalog_product' => $item->product_id !== null,
            'product_handle' => $item->product?->handle,
            'sku' => $item->sku,
            'vendor' => $item->vendor,
            // Always return CAD unit cost for display/costing; for foreign vendors, uses FX on the parent PO.
            'unit_cost' => $this->money2($this->unitCostCadForItem($item)),
            'vendor_unit_cost' => $this->money2($item->vendor_unit_cost),
            'qty_ordered' => $item->qty_ordered,
            'qty_shipped' => $item->qty_shipped,
            'qty_received' => $item->qty_received,
            'qty_damaged' => $item->qty_damaged,
            'available' => $item->getAttribute('product_available') !== null
                ? (int) $item->getAttribute('product_available')
                : $item->product?->available_qty,
            'maintain' => $item->getAttribute('product_maintain') !== null
                ? (int) $item->getAttribute('product_maintain')
                : $item->product?->maintain_qty,
            'not_arrived' => (int) ($item->getAttribute('product_not_arrived') ?? 0),
            'reorder' => (int) ($item->getAttribute('product_reorder') ?? 0),
            'total_ordered' => (int) ($item->getAttribute('product_total_ordered') ?? 0),
            'total_sold' => (int) ($item->getAttribute('product_total_sold') ?? 0),
            'latest_landed_unit_cost' => $this->money2(
                $item->getAttribute('product_latest_landed_unit_cost') ?? $item->product?->latest_landed_unit_cost,
            ),
            'selling_price' => $this->money2(
                $item->getAttribute('product_selling_price')
                    ?? $item->product?->sellingPrice?->selling_price,
            ),
            'multiplier' => $item->getAttribute('product_multiplier'),
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isProductVendorMissing(PurchaseOrderItem $item): bool
    {
        if ($item->product_id === null) {
            return false;
        }

        $vendor = $item->product?->vendor;

        return $vendor === null || trim((string) $vendor) === '';
    }
}
