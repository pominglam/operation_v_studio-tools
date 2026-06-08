<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorderSyncLog;

final class PlamodPreorderStatusService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        /** @var PlamodPreorderSyncLog|null $latest */
        $latest = PlamodPreorderSyncLog::query()->orderByDesc('id')->first();

        if ($latest === null) {
            return [
                'status' => 'never',
                'sync_log_id' => null,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'counts' => [],
                'error_summary' => null,
            ];
        }

        $counts = $latest->counts_json ?? [];
        $status = (string) $latest->status;
        if ($status === 'running') {
            $total = (int) ($counts['images_total'] ?? 0);
            $done = (int) ($counts['images_completed'] ?? 0) + (int) ($counts['images_failed'] ?? 0);
            if ($total > 0 && $done < $total) {
                $counts['phase'] = 'images';
            }
        }

        return [
            'status' => $status,
            'sync_log_id' => (int) $latest->id,
            'started_at' => $latest->started_at?->toIso8601String(),
            'finished_at' => $latest->finished_at?->toIso8601String(),
            'duration_ms' => $latest->duration_ms,
            'counts' => $counts,
            'error_summary' => $latest->error_summary,
        ];
    }
}
