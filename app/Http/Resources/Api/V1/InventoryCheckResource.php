<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\InventoryCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<InventoryCheck> */
final class InventoryCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InventoryCheck $check */
        $check = $this->resource;

        return [
            'id' => $check->uuid,
            'name' => $check->name,
            'source' => $check->source,
            'notes' => $check->notes,
            'uploaded_file_path' => $check->uploaded_file_path,
            'workflow_state' => $check->workflow_state,
            'created_by_role' => $check->created_by_role,
            'applied_at' => optional($check->applied_at)->toISOString(),
            'counts' => [
                'items' => (int) ($check->items_count ?? 0),
                'matched' => (int) ($check->matched_count ?? 0),
                'unmatched' => (int) ($check->unmatched_count ?? 0),
                'ambiguous' => (int) ($check->ambiguous_count ?? 0),
                'applied' => (int) ($check->applied_count ?? 0),
            ],
            'items' => InventoryCheckItemResource::collection($this->whenLoaded('items')),
            'created_at' => optional($check->created_at)->toISOString(),
            'updated_at' => optional($check->updated_at)->toISOString(),
        ];
    }
}




