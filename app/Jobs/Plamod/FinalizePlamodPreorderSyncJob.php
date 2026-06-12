<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderSyncFailureRecorder;
use App\Services\Plamod\PlamodPreorderSyncFinalizer;
use App\Services\Plamod\PlamodPreorderSyncLogger;
use App\Services\Plamod\PlamodPreorderSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class FinalizePlamodPreorderSyncJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncLogId,
    ) {
        $this->onQueue(PlamodPreorderSyncOrchestrator::QUEUE);
    }

    public function handle(
        PlamodPreorderSyncFinalizer $finalizer,
        PlamodPreorderSyncLogger $logger,
    ): void {
        $recorder = new PlamodPreorderSyncFailureRecorder;
        $failureMeta = $recorder->finalize($this->syncLogId);

        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($this->syncLogId);
        $logger->updateCounts($log, $failureMeta);

        $finalizer->finalize($this->syncLogId);
    }
}
