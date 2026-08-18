<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrder;
use App\Services\PurchaseOrders\PurchaseOrderShipmentMethodService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<PurchaseOrder> */
final class PurchaseOrderResource extends JsonResource
{
    private function statusFor(PurchaseOrder $po): string
    {
        if ($po->fully_on_shelves_date !== null) {
            return 'on_shelves';
        }
        if ($po->received_date !== null) {
            return 'received';
        }
        if ($po->shipped_date !== null) {
            return 'shipped';
        }
        if ($po->ordered_date !== null) {
            return 'ordered';
        }

        return 'draft';
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

    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** @return array<int, string> */
    private function trackingNumbers(PurchaseOrder $po): array
    {
        return array_values(array_filter(
            $po->shipment_tracking_numbers ?? [],
            static fn (mixed $number): bool => is_string($number) && trim($number) !== '',
        ));
    }

    private function decimal6(?string $value): ?string
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

        return number_format((float) $clean, 6, '.', '');
    }

    private function invertDecimal6(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || ! is_numeric($trimmed) || (float) $trimmed == 0.0) {
            return null;
        }

        if (extension_loaded('bcmath')) {
            /** @var string $out */
            $out = bcdiv('1', $trimmed, 6);

            return $out;
        }

        $inv = 1 / (float) $trimmed;

        return number_format($inv, 6, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrder $po */
        $po = $this->resource;

        return [
            'id' => $po->uuid,
            'vendor' => $po->vendor,
            'supplier_order_id' => $this->trimmedOrNull($po->supplier_order_id),
            'shipment_tracking_numbers' => $this->trackingNumbers($po),
            'vendor_currency_code' => $po->vendor_currency_code,
            'ordered_date' => $po->ordered_date?->toDateString(),
            'shipped_date' => $po->shipped_date?->toDateString(),
            'estimated_arrival_date' => $po->estimated_arrival_date?->toDateString(),
            'received_date' => $po->received_date?->toDateString(),
            'fully_on_shelves_date' => $po->fully_on_shelves_date?->toDateString(),
            'shipping_total' => $this->money2($po->shipping_total),
            'surcharge_total' => $this->money2($po->surcharge_total),
            'product_total' => $this->money2($po->product_total),
            'vendor_product_total' => $this->money2($po->vendor_product_total),
            'vendor_shipping_total' => $this->money2($po->vendor_shipping_total),
            // Stored as "vendor -> CAD" for internal cost conversion.
            'fx_rate_to_cad' => $this->decimal6($po->fx_rate_to_cad),
            // Display-friendly: "CAD -> vendor" (e.g. ~5.x for HKD).
            'fx_rate_cad_to_vendor' => $this->invertDecimal6($this->decimal6($po->fx_rate_to_cad)),
            'notes' => $po->notes,
            'is_done' => (bool) $po->is_done,
            'exclude_from_latest_arrivals_ordering' => (bool) $po->exclude_from_latest_arrivals_ordering,
            'workflow_checklist' => app(\App\Services\PurchaseOrders\PurchaseOrderWorkflowChecklistNormalizer::class)
                ->normalize(is_array($po->workflow_checklist_json) ? $po->workflow_checklist_json : null),
            'status' => $this->statusFor($po),
            'shipment_method' => app(PurchaseOrderShipmentMethodService::class)->normalize($po->shipment_method),
            'counts' => [
                'items' => $po->relationLoaded('items') ? (int) $po->items->count() : (int) ($po->items_count ?? 0),
                'unassigned_product_vendor' => $po->relationLoaded('items')
                    ? $this->countUnassignedProductVendors($po)
                    : 0,
            ],
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => optional($po->created_at)->toISOString(),
            'updated_at' => optional($po->updated_at)->toISOString(),
        ];
    }

    private function countUnassignedProductVendors(PurchaseOrder $po): int
    {
        if (! $po->relationLoaded('items')) {
            return 0;
        }

        $count = 0;
        foreach ($po->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $vendor = $item->product?->vendor;
            if ($vendor === null || trim((string) $vendor) === '') {
                $count++;
            }
        }

        return $count;
    }
}
