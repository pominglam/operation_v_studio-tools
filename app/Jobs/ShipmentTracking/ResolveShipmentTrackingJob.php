<?php

declare(strict_types=1);

namespace App\Jobs\ShipmentTracking;

use App\Services\ShipmentTracking\ShipmentTrackingResolveService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ResolveShipmentTrackingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'shipment_tracking';

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $resolutionId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->resolutionId;
    }

    public function handle(ShipmentTrackingResolveService $resolver): void
    {
        $resolver->resolve($this->resolutionId);
    }
}
