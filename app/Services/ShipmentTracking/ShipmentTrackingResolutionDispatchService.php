<?php

declare(strict_types=1);

namespace App\Services\ShipmentTracking;

use App\DAL\ShipmentTracking\ShipmentTrackingResolutionRepository;
use App\DTOs\ShipmentTracking\TrackingResolutionDTO;
use App\Jobs\ShipmentTracking\ResolveShipmentTrackingJob;
use App\Models\ShipmentTrackingResolution;

final class ShipmentTrackingResolutionDispatchService
{
    public function __construct(
        private readonly ShipmentTrackingResolutionRepository $resolutions,
    ) {}

    /**
     * @param  array<int, string>  $trackingNumbers
     * @return array<int, TrackingResolutionDTO>
     */
    public function dispatch(array $trackingNumbers): array
    {
        $output = [];
        foreach ($this->uniqueNumbers($trackingNumbers) as $trackingNumber) {
            $key = $this->trackingKey($trackingNumber);
            $resolution = $this->resolutions->findByKey($key);
            $isNew = $resolution === null;
            $resolution ??= $this->resolutions->create($key, $trackingNumber);

            if ($isNew || $this->shouldQueue($resolution)) {
                $resolution->status = 'queued';
                $resolution->error_summary = null;
                $this->resolutions->save($resolution);
                ResolveShipmentTrackingJob::dispatch((int) $resolution->id);
            }

            $output[] = TrackingResolutionDTO::fromModel($trackingNumber, $resolution);
        }

        return $output;
    }

    /** @param array<int, string> $trackingNumbers
     * @return array<int, string>
     */
    private function uniqueNumbers(array $trackingNumbers): array
    {
        $unique = [];
        foreach ($trackingNumbers as $number) {
            $trimmed = trim($number);
            $unique[$this->trackingKey($trimmed)] ??= $trimmed;
        }

        return array_values($unique);
    }

    private function trackingKey(string $trackingNumber): string
    {
        $canonical = preg_replace('/\s+/', '', mb_strtoupper($trackingNumber)) ?? '';

        return hash('sha256', $canonical);
    }

    private function shouldQueue(ShipmentTrackingResolution $resolution): bool
    {
        if ($resolution->status === 'resolved') {
            return false;
        }
        if (in_array($resolution->status, ['queued', 'resolving'], true)) {
            return $resolution->updated_at?->lt(now()->subMinutes(15)) ?? false;
        }

        return $resolution->retry_after === null || $resolution->retry_after->isPast();
    }
}
