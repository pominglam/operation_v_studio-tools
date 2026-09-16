<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\SyncPlamodInstockJob;
use App\Models\PlamodInstockSyncLog;
use App\Support\Plamod\PlamodInstockFilterChunks;

final class PlamodInstockDispatchService
{
    public function __construct(
        private readonly PlamodInstockSyncLogger $logger,
        private readonly PlamodScraperHealthService $health,
    ) {}

    /**
     * @return array{ok: bool, sync_log_id: int|null, error_message?: string, skipped?: bool}
     */
    public function dispatch(bool $skipIfActive = false): array
    {
        if ($skipIfActive && $this->hasActiveSync()) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'skipped' => true,
                'error_message' => 'A PLAMOD refresh is already running.',
            ];
        }

        $ready = $this->health->assertPreordersExportReady();
        if (! $ready['ok']) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'error_message' => (string) ($ready['error_message'] ?? 'Plamod scraper is not ready.'),
            ];
        }

        $log = $this->logger->queue();
        SyncPlamodInstockJob::dispatch((int) $log->id);

        return [
            'ok' => true,
            'sync_log_id' => (int) $log->id,
        ];
    }

    /**
     * @param  array<int, array{name: string, tab: string, category_id: string|null, expected: int}>  $filters
     * @return array{ok: bool, sync_log_id: int|null, error_message?: string}
     */
    public function dispatchRetry(array $filters): array
    {
        if ($this->hasActiveSync()) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'error_message' => 'A PLAMOD refresh is already running.',
            ];
        }

        $allowed = $this->allowedRetryFilters();
        $selected = $this->intersectRequestedFilters($filters, $allowed);
        if ($selected === []) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'error_message' => 'Select at least one failed brand filter from the last refresh.',
            ];
        }

        $ready = $this->health->assertPreordersExportReady();
        if (! $ready['ok']) {
            return [
                'ok' => false,
                'sync_log_id' => null,
                'error_message' => (string) ($ready['error_message'] ?? 'Plamod scraper is not ready.'),
            ];
        }

        $log = $this->logger->queue();
        $this->logger->progress($log, [
            'phase' => 'retry',
            'retry_filters' => $selected,
        ]);
        SyncPlamodInstockJob::dispatch((int) $log->id, $selected);

        return [
            'ok' => true,
            'sync_log_id' => (int) $log->id,
        ];
    }

    private function hasActiveSync(): bool
    {
        return PlamodInstockSyncLog::query()
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    /**
     * @return array<int, array{name: string, tab: string, category_id: string|null, expected: int, rows: int, error: string|null}>
     */
    private function allowedRetryFilters(): array
    {
        /** @var PlamodInstockSyncLog|null $latest */
        $latest = PlamodInstockSyncLog::query()
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
        $chunks = is_array($latest?->counts_json['filter_chunks'] ?? null)
            ? $latest->counts_json['filter_chunks']
            : [];

        return PlamodInstockFilterChunks::failed($chunks);
    }

    /**
     * @param  array<int, array{name: string, tab: string, category_id: string|null, expected: int}>  $requested
     * @param  array<int, array{name: string, tab: string, category_id: string|null, expected: int, rows: int, error: string|null}>  $allowed
     * @return array<int, array{name: string, tab: string, category_id: string|null, expected: int}>
     */
    private function intersectRequestedFilters(array $requested, array $allowed): array
    {
        $allowedByKey = [];
        foreach ($allowed as $filter) {
            $allowedByKey[PlamodInstockFilterChunks::key($filter)] = $filter;
        }

        $selected = [];
        foreach ($requested as $filter) {
            $match = $allowedByKey[PlamodInstockFilterChunks::key($filter)] ?? null;
            if ($match === null) {
                continue;
            }
            $selected[] = [
                'name' => $match['name'],
                'tab' => $match['tab'],
                'category_id' => $match['category_id'] ?? $filter['category_id'],
                'expected' => (int) ($match['expected'] ?? $filter['expected']),
            ];
        }

        return $selected;
    }
}
