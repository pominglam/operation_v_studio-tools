<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Services\Plamod\PlamodPreorderManufacturerFilterDiscoverService;
use App\Services\Plamod\PlamodPreorderManufacturerFilterDiscoverStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DiscoverPlamodPreorderManufacturerFiltersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly string $jobId,
        public readonly int $manufacturerId = 1,
    ) {}

    public function handle(
        PlamodPreorderManufacturerFilterDiscoverService $discover,
        PlamodPreorderManufacturerFilterDiscoverStore $store,
    ): void {
        $store->markRunning($this->jobId);

        try {
            $result = $discover->discover($this->manufacturerId);
            if (($result['ok'] ?? false) !== true) {
                $store->fail($this->jobId, (string) ($result['error_message'] ?? 'Discover failed'));

                return;
            }

            $store->complete($this->jobId, $result);
        } catch (\Throwable $e) {
            $store->fail($this->jobId, $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $store = app(PlamodPreorderManufacturerFilterDiscoverStore::class);
        $payload = $store->get($this->jobId);
        if ($payload !== null && ($payload['status'] ?? '') !== 'completed') {
            $store->fail($this->jobId, $exception->getMessage());
        }
    }
}
