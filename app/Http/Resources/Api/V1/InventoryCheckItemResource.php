<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\InventoryCheckItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<InventoryCheckItem> */
final class InventoryCheckItemResource extends JsonResource
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryCheckItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'product_id' => $item->product?->uuid,
            'handle' => $item->handle,
            'vendor' => $item->vendor,
            'sku' => $item->sku,
            'type' => $item->type,
            'product_name' => $item->product_name,
            'english_name' => $item->english_name,
            'available_amount' => $item->available_amount,
            'selling_price' => $this->money2($item->product?->price),
            'quantity_in_store' => $item->quantity_in_store,
            'difference' => $item->difference,
            'notes' => $item->notes,
            'match_status' => $item->match_status,
            'match_error' => $item->match_error,
            'applied' => (bool) $item->applied,
            'applied_at' => optional($item->applied_at)->toISOString(),
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }
}




