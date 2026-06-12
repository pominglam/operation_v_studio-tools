<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Services\Plamod\PlamodPreorderLiveHitImportService;
use App\Services\Plamod\PlamodPreorderLiveSearchStore;
use App\Services\Plamod\PlamodPreorderSearchLinesService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunPlamodPreorderLiveSearchJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly string $jobId,
        public readonly array $lines,
    ) {}

    public function handle(
        PlamodPreorderSearchLinesService $search,
        PlamodPreorderLiveSearchStore $store,
        PlamodPreorderLiveHitImportService $liveImport,
    ): void {
        $store->markRunning($this->jobId);

        try {
            $live = $search->searchLive($this->lines);
            $liveImport->upsertResourceRows($live['rows']);
            $store->complete($this->jobId, $live['plamod_only'], $live['not_found'], $live['rows']);
        } catch (\Throwable $e) {
            $store->fail($this->jobId, $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $store = app(PlamodPreorderLiveSearchStore::class);
        $payload = $store->get($this->jobId);
        if ($payload !== null && ($payload['status'] ?? '') !== 'completed') {
            $store->fail($this->jobId, $exception->getMessage());
        }
    }
}
