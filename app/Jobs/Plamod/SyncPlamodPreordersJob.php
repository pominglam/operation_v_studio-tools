<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Services\Plamod\PlamodPreorderSyncAutoResume;
use App\Services\Plamod\PlamodPreorderSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncPlamodPreordersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncLogId,
        public readonly bool $resume = false,
        public readonly int $autoResumeAttempt = 0,
    ) {
        $this->onQueue(PlamodPreorderSyncOrchestrator::QUEUE);
    }

    public function handle(PlamodPreorderSyncOrchestrator $orchestrator): void
    {
        $orchestrator->start($this->syncLogId, $this->resume);
    }

    public function failed(\Throwable $exception): void
    {
        app(PlamodPreorderSyncAutoResume::class)->scheduleIfRecoverable(
            $this->syncLogId,
            $exception,
            $this->autoResumeAttempt,
        );
    }
}
