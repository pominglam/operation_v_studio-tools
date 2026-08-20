<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\ShipmentTracking\TrackingResolutionDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrackingResolutionDTO */
final class ShipmentTrackingResolutionResource extends JsonResource
{
    /** @return array<string, string|null> */
    public function toArray(Request $request): array
    {
        /** @var TrackingResolutionDTO $resolution */
        $resolution = $this->resource;

        return $resolution->toArray();
    }
}
