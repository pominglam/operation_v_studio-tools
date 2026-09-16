<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockSyncLog;
use App\Support\Plamod\PlamodInstockFilterChunks;

final class PlamodInstockStatusService
{
    public function __construct(
        private readonly PlamodInstockExportProgressReader $progress,
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
                'failed_filters' => [],
            ];
        }

        $counts = $latest->counts_json ?? [];

        if (in_array((string) $latest->status, ['queued', 'running'], true)) {
            $progress = $this->progress->read();
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
            'failed_filters' => $this->failedFilters($latest),
        ];
    }

    /**
     * @return array<int, array{name: string, tab: string, category_id: string|null, expected: int, rows: int, error: string|null}>
     */
    private function failedFilters(PlamodInstockSyncLog $latest): array
    {
        $chunks = is_array($latest->counts_json['filter_chunks'] ?? null)
            ? $latest->counts_json['filter_chunks']
            : [];
        if ($chunks === []) {
            /** @var PlamodInstockSyncLog|null $previous */
            $previous = PlamodInstockSyncLog::query()
                ->where('status', 'completed')
                ->where('id', '!=', $latest->id)
                ->orderByDesc('id')
                ->first();
            $chunks = is_array($previous?->counts_json['filter_chunks'] ?? null)
                ? $previous->counts_json['filter_chunks']
                : [];
        }

        return PlamodInstockFilterChunks::failed($chunks);
    }
}
