<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin;

use App\Models\Shopify\ShopifySyncLog;

final class ShopifyMaintenanceRunLogger
{
    public function queue(string $syncKey): ShopifySyncLog
    {
        ShopifySyncLog::query()
            ->where('sync_key', $syncKey)
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('finished_at')
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_summary' => 'Superseded by a newer queued run.',
            ]);

        return ShopifySyncLog::query()->create([
            'sync_key' => $syncKey,
            'status' => 'queued',
            'started_at' => now(),
            'checkpoint_json' => ['queued_at' => now()->toIso8601String()],
            'counts_json' => [],
        ]);
    }

    public function markRunning(ShopifySyncLog $log): ShopifySyncLog
    {
        $log->forceFill([
            'status' => 'running',
            'started_at' => now(),
        ])->save();

        return $log->refresh();
    }

    public function start(string $syncKey): ShopifySyncLog
    {
        return ShopifySyncLog::query()->create([
            'sync_key' => $syncKey,
            'status' => 'running',
            'started_at' => now(),
            'checkpoint_json' => [],
            'counts_json' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    public function complete(ShopifySyncLog $log, array $counts = []): ShopifySyncLog
    {
        $started = $log->started_at ?? now();
        $log->forceFill([
            'status' => 'completed',
            'finished_at' => now(),
            'duration_ms' => (int) max(0, $started->diffInMilliseconds(now())),
            'counts_json' => $counts,
            'records_fetched' => (int) ($counts['fetched'] ?? $counts['matched'] ?? 0),
            'records_updated' => (int) ($counts['updated'] ?? 0),
        ])->save();

        return $log->refresh();
    }

    public function fail(ShopifySyncLog $log, string $error): ShopifySyncLog
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
