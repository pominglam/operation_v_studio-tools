<?php

declare(strict_types=1);

namespace App\Contracts\ShipmentTracking;

use App\DTOs\ShipmentTracking\TrackingProbeResultDTO;

interface TrackingBrowser
{
    public function resolve(string $trackingNumber): TrackingProbeResultDTO;
}
