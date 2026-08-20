<?php

declare(strict_types=1);

namespace App\DAL\ShipmentTracking;

use App\Models\ShipmentTrackingResolution;

interface ShipmentTrackingResolutionRepository
{
    public function findByKey(string $trackingKey): ?ShipmentTrackingResolution;

    public function findByIdOrFail(int $id): ShipmentTrackingResolution;

    public function create(string $trackingKey, string $trackingNumber): ShipmentTrackingResolution;

    public function save(ShipmentTrackingResolution $resolution): ShipmentTrackingResolution;
}
