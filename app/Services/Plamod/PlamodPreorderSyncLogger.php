<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorderSyncLog;

final class PlamodPreorderSyncLogger
{
    public function queue(): PlamodPreorderSyncLog
    {
        PlamodPreorderSyncLog::query()
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_summary' => 'Superseded by a newer queued run.',
            ]);

        return PlamodPreorderSyncLog::query()->create([
            'status' => 'queued',
            'started_at' => now(),
            'counts_json' => [],
        ]);
    }

    public function markRunning(PlamodPreorderSyncLog $log): PlamodPreorderSyncLog
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
    public function updateCounts(PlamodPreorderSyncLog $log, array $counts): PlamodPreorderSyncLog
    {
        $merged = array_merge($log->counts_json ?? [], $counts);
        $log->forceFill(['counts_json' => $merged])->save();

        return $log->refresh();
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public function complete(PlamodPreorderSyncLog $log, array $counts = []): PlamodPreorderSyncLog
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

    public function fail(PlamodPreorderSyncLog $log, string $error): PlamodPreorderSyncLog
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

    public function incrementImageCount(PlamodPreorderSyncLog $log, bool $success): PlamodPreorderSyncLog
    {
        $counts = $log->counts_json ?? [];
        $key = $success ? 'images_completed' : 'images_failed';
        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
        $log->forceFill(['counts_json' => $counts])->save();

        return $log->refresh();
    }

    public function maybeCompleteAfterImages(PlamodPreorderSyncLog $log): PlamodPreorderSyncLog
    {
        $counts = $log->counts_json ?? [];
        $total = (int) ($counts['images_total'] ?? 0);
        $done = (int) ($counts['images_completed'] ?? 0) + (int) ($counts['images_failed'] ?? 0);

        if ($log->status !== 'running' || $total <= 0 || $done < $total) {
            return $log;
        }

        return $this->complete($log, ['phase' => 'done']);
    }
}
