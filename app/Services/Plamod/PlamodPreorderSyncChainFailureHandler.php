<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorderSyncLog;
use Throwable;

final class PlamodPreorderSyncChainFailureHandler
{
    public function __construct(
        private readonly PlamodPreorderSyncAutoResume $autoResume,
        private readonly PlamodPreorderSyncLogger $logger,
    ) {}

    public function handle(int $syncLogId, Throwable $exception): void
    {
        if ($this->autoResume->scheduleIfRecoverable($syncLogId, $exception)) {
            return;
        }

        /** @var PlamodPreorderSyncLog|null $log */
        $log = PlamodPreorderSyncLog::query()->find($syncLogId);
        if ($log !== null && $log->status !== 'completed' && $log->status !== 'failed') {
            $this->logger->fail($log, $exception->getMessage());
        }
    }
}
