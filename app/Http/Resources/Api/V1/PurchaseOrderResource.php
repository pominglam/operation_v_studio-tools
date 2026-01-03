<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<PurchaseOrder> */
final class PurchaseOrderResource extends JsonResource
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
            'vendor_currency_code' => $po->vendor_currency_code,
            'ordered_date' => $po->ordered_date?->toDateString(),
            'shipped_date' => $po->shipped_date?->toDateString(),
            'received_date' => $po->received_date?->toDateString(),
            'fully_on_shelves_date' => $po->fully_on_shelves_date?->toDateString(),
            'shipping_total' => $this->money2($po->shipping_total),
            'surcharge_total' => $this->money2($po->surcharge_total),
            'product_total' => $this->money2($po->product_total),
            'vendor_product_total' => $this->money2($po->vendor_product_total),
            // Stored as "vendor -> CAD" for internal cost conversion.
            'fx_rate_to_cad' => $this->decimal6($po->fx_rate_to_cad),
            // Display-friendly: "CAD -> vendor" (e.g. ~5.x for HKD).
            'fx_rate_cad_to_vendor' => $this->invertDecimal6($this->decimal6($po->fx_rate_to_cad)),
            'notes' => $po->notes,
            'counts' => [
                'items' => $po->relationLoaded('items') ? (int) $po->items->count() : (int) ($po->items_count ?? 0),
            ],
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => optional($po->created_at)->toISOString(),
            'updated_at' => optional($po->updated_at)->toISOString(),
        ];
    }
}


