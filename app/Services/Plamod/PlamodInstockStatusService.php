<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockSyncLog;
use App\Services\Products\Http\PlamodScraper;

final class PlamodInstockStatusService
{
    public function __construct(
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        /** @var PlamodInstockSyncLog|null $latest */
        $latest = PlamodInstockSyncLog::query()->orderByDesc('id')->first();

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

        if (in_array((string) $latest->status, ['queued', 'running'], true)) {
            $progress = $this->scraper->instockExportProgress();
            if (($progress['active'] ?? false) === true) {
                $counts = array_merge($counts, $progress);
            } elseif (($counts['phase'] ?? '') === '') {
                $counts['phase'] = (string) $latest->status === 'queued' ? 'queued' : 'export';
            }
        }

        return [
            'status' => (string) $latest->status,
            'sync_log_id' => (int) $latest->id,
            'started_at' => $latest->started_at?->toIso8601String(),
            'finished_at' => $latest->finished_at?->toIso8601String(),
            'duration_ms' => $latest->duration_ms,
            'counts' => $counts,
            'error_summary' => $latest->error_summary,
        ];
    }
}
