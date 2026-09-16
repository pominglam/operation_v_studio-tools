<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ProductSavedView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductSavedView */
final class ProductSavedViewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'snapshot' => $this->snapshot,
            'visible_columns' => $this->visible_columns,
        ];
    }
}
