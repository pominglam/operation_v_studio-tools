<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\StoreEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StoreEvent */
final class StoreEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'notes' => $this->notes,
            'cancelled' => $this->cancelled_at !== null,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
