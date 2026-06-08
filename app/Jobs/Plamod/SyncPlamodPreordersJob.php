<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderSyncLogger;
use App\Services\Plamod\PlamodPreorderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncPlamodPreordersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $syncLogId,
    ) {}

    public function handle(PlamodPreorderSyncService $sync, PlamodPreorderSyncLogger $logger): void
    {
        try {
            $sync->run($this->syncLogId);
        } catch (\Throwable $e) {
            /** @var PlamodPreorderSyncLog|null $log */
            $log = PlamodPreorderSyncLog::query()->find($this->syncLogId);
            if ($log !== null && $log->status !== 'failed') {
                $logger->fail($log, $e->getMessage());
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        /** @var PlamodPreorderSyncLog|null $log */
        $log = PlamodPreorderSyncLog::query()->find($this->syncLogId);
        if ($log !== null && $log->status !== 'failed' && $log->status !== 'completed') {
            app(PlamodPreorderSyncLogger::class)->fail($log, $exception->getMessage());
        }
    }
}
