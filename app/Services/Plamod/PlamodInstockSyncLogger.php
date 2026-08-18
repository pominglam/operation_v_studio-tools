<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockSyncLog;

final class PlamodInstockSyncLogger
{
    public function queue(): PlamodInstockSyncLog
    {
        PlamodInstockSyncLog::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_summary' => 'Superseded by a newer queued run.',
            ]);

        return PlamodInstockSyncLog::query()->create([
            'status' => 'queued',
            'started_at' => now(),
            'counts_json' => [],
        ]);
    }

    public function markRunning(PlamodInstockSyncLog $log): PlamodInstockSyncLog
    {
        $log->forceFill([
            'status' => 'running',
            'started_at' => $log->started_at ?? now(),
        ])->save();

        return $log->refresh();
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public function progress(PlamodInstockSyncLog $log, array $counts): PlamodInstockSyncLog
    {
        $log->forceFill([
            'counts_json' => array_merge($log->counts_json ?? [], $counts),
        ])->save();

        return $log->refresh();
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public function complete(PlamodInstockSyncLog $log, array $counts = []): PlamodInstockSyncLog
    {
        $started = $log->started_at ?? now();
        $log->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
            'duration_ms' => (int) max(0, $started->diffInMilliseconds(now())),
            'counts_json' => array_merge($log->counts_json ?? [], $counts),
        ])->save();

        return $log->refresh();
    }

    public function fail(PlamodInstockSyncLog $log, string $error): PlamodInstockSyncLog
    {
        $started = $log->started_at ?? now();
        $log->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
            'duration_ms' => (int) max(0, $started->diffInMilliseconds(now())),
            'error_summary' => mb_substr($error, 0, 5000),
        ])->save();

        return $log->refresh();
    }
}
