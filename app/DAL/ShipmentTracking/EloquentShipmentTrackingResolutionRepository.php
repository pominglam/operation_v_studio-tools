<?php

declare(strict_types=1);

namespace App\DAL\ShipmentTracking;

use App\Models\ShipmentTrackingResolution;

final class EloquentShipmentTrackingResolutionRepository implements ShipmentTrackingResolutionRepository
{
    public function findByKey(string $trackingKey): ?ShipmentTrackingResolution
    {
        return ShipmentTrackingResolution::query()
            ->where('tracking_key', $trackingKey)
            ->first();
    }

    public function findByIdOrFail(int $id): ShipmentTrackingResolution
    {
        return ShipmentTrackingResolution::query()->findOrFail($id);
    }

    public function create(string $trackingKey, string $trackingNumber): ShipmentTrackingResolution
    {
        return ShipmentTrackingResolution::query()->firstOrCreate(
            ['tracking_key' => $trackingKey],
            [
                'tracking_number' => $trackingNumber,
                'status' => 'queued',
                'attempt_count' => 0,
            ],
        );
    }

    public function save(ShipmentTrackingResolution $resolution): ShipmentTrackingResolution
    {
        $resolution->save();

        return $resolution;
    }
}
