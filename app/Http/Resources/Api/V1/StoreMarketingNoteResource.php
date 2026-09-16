<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\StoreMarketingNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StoreMarketingNote */
final class StoreMarketingNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'happened_on' => $this->happened_on->toDateString(),
            'notes' => $this->notes,
        ];
    }
}
