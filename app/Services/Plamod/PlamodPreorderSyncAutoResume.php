<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\SyncPlamodPreordersJob;
use App\Models\PlamodPreorderSyncLog;
use Throwable;

final class PlamodPreorderSyncAutoResume
{
    public function __construct(
        private readonly PlamodPreorderSyncLogger $logger,
    ) {}

    /**
     * Schedule a delayed resume when checkpoint progress exists and the failure is recoverable.
     *
     * @param  int|null  $currentAttempt  Explicit attempt counter (from the failing job); when null, read from sync log counts.
     */
    public function scheduleIfRecoverable(int $syncLogId, Throwable|string $exception, ?int $currentAttempt = null): bool
    {
        /** @var PlamodPreorderSyncLog|null $log */
        $log = PlamodPreorderSyncLog::query()->find($syncLogId);
        if ($log === null || $log->status === 'completed') {
            return false;
        }

        $message = $exception instanceof Throwable ? $exception->getMessage() : $exception;
        $checkpoint = PlamodPreorderSyncCheckpoint::fromCounts($log->counts_json ?? []);
        $attempt = $currentAttempt ?? $checkpoint['auto_resume_attempt'];

        if ($attempt >= PlamodPreorderSyncCheckpoint::MAX_AUTO_RESUME_ATTEMPTS) {
            $this->fail($log, $message);

            return false;
        }

        if (! PlamodPreorderSyncCheckpoint::hasProgress($log)
            || ! PlamodPreorderSyncCheckpoint::isRecoverableFailure($message)) {
            $this->fail($log, $message);

            return false;
        }

        $nextAttempt = $attempt + 1;
        $this->logger->updateCounts($log, [
            'phase' => 'queued',
            'auto_resume_attempt' => $nextAttempt,
            'auto_resume_reason' => mb_substr($message, 0, 500),
            'auto_resume_scheduled_at' => now()->toIso8601String(),
        ]);
        $log->forceFill([
            'status' => 'queued',
            'error_summary' => null,
            'finished_at' => null,
        ])->save();

        SyncPlamodPreordersJob::dispatch($syncLogId, resume: true, autoResumeAttempt: $nextAttempt)
            ->delay(now()->addSeconds(45));

        return true;
    }

    public function fail(PlamodPreorderSyncLog $log, string $message): void
    {
        if ($log->status !== 'failed') {
            $this->logger->fail($log, $message);
        }
    }
}
