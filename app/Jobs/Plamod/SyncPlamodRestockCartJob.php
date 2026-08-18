<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodRestockCartRun;
use App\Services\Plamod\PlamodRestockCartRunLogger;
use App\Services\Plamod\PlamodRestockCartRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SyncPlamodRestockCartJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7260;

    public int $tries = 1;

    public function __construct(
        public readonly int $cartRunId,
    ) {
        $this->onQueue(PlamodRestockCartRunService::QUEUE);
    }

    public function handle(PlamodRestockCartRunService $cartRun): void
    {
        $cartRun->run($this->cartRunId);
    }

    public function failed(?Throwable $exception): void
    {
        $run = PlamodRestockCartRun::query()->find($this->cartRunId);
        if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }

        app(PlamodRestockCartRunLogger::class)->fail(
            $run,
            $exception?->getMessage() ?? 'PLAMOD cart job failed before completion.',
            ['phase' => 'failed'],
        );
    }
}
