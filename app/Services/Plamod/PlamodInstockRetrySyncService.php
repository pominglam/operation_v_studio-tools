<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockItem;
use App\Models\PlamodInstockSyncLog;
use App\Services\Products\Http\PlamodScraper;
use App\Support\Plamod\PlamodInstockFilterChunks;

final class PlamodInstockRetrySyncService
{
    public function __construct(
        private readonly PlamodScraper $scraper,
        private readonly PlamodInstockCsvImportService $import,
        private readonly PlamodInstockSyncLogger $logger,
    ) {}

    /**
     * @param  array<int, array{name: string, tab: string, category_id: string|null, expected: int}>  $retryFilters
     */
    public function run(int $syncLogId, array $retryFilters): void
    {
        /** @var PlamodInstockSyncLog|null $log */
        $log = PlamodInstockSyncLog::query()->find($syncLogId);
        if ($log === null) {
            return;
        }

        $this->logger->markRunning($log);
        $export = $this->scraper->exportManufacturerInstockMerged(1, $retryFilters);
        if (($export['ok'] ?? false) !== true) {
            $this->logger->fail($log, (string) ($export['error_message'] ?? 'PLAMOD filter retry failed.'));

            return;
        }

        $csvPath = (string) ($export['csv_storage_path'] ?? '');
        if ($csvPath === '') {
            $this->logger->fail($log, 'PLAMOD filter retry returned no CSV path.');

            return;
        }

        try {
            $this->logger->progress($log, ['phase' => 'import']);
            $counts = $this->import->importFromStoragePath($csvPath, $syncLogId, replaceMissing: false);
        } catch (\Throwable $e) {
            $this->logger->fail($log, $e->getMessage());

            return;
        }

        $this->completeRetry($log, $export, $counts, $csvPath);
    }

    /**
     * @param  array<string, mixed>  $export
     * @param  array{rows_parsed: int, rows_upserted: int, rows_skipped: int}  $counts
     */
    private function completeRetry(
        PlamodInstockSyncLog $log,
        array $export,
        array $counts,
        string $csvPath,
    ): void {
        $previous = PlamodInstockSyncLog::query()
            ->where('status', 'completed')
            ->where('id', '<', $log->id)
            ->orderByDesc('id')
            ->first();
        $previousCounts = is_array($previous?->counts_json) ? $previous->counts_json : [];
        $previousChunks = is_array($previousCounts['filter_chunks'] ?? null)
            ? $previousCounts['filter_chunks']
            : [];
        $retryChunks = is_array($export['filter_chunks'] ?? null) ? $export['filter_chunks'] : [];
        $tableCount = PlamodInstockItem::query()->count();
        $expected = (int) ($previousCounts['expected_row_count'] ?? $export['expected_row_count'] ?? 0);

        $this->logger->complete($log, [
            'rows_parsed' => $counts['rows_parsed'],
            'rows_upserted' => $tableCount,
            'rows_skipped' => $counts['rows_skipped'],
            'rows_retry_upserted' => $counts['rows_upserted'],
            'row_count' => $tableCount,
            'expected_row_count' => $expected > 0 ? $expected : null,
            'filter_mode' => $export['filter_mode'] ?? ($previousCounts['filter_mode'] ?? null),
            'filter_chunks' => PlamodInstockFilterChunks::merge($previousChunks, $retryChunks),
            'csv_storage_path' => $csvPath,
            'retry' => true,
        ]);
    }
}
