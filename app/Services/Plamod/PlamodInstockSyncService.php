<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockSyncLog;
use App\Services\Products\Http\PlamodScraper;

final class PlamodInstockSyncService
{
    public const string QUEUE = 'plamod_sync';

    public function __construct(

        private readonly PlamodScraper $scraper,

        private readonly PlamodInstockCsvImportService $import,

        private readonly PlamodInstockSyncLogger $logger,

    ) {}

    public function run(int $syncLogId): void
    {

        /** @var PlamodInstockSyncLog|null $log */
        $log = PlamodInstockSyncLog::query()->find($syncLogId);

        if ($log === null) {

            return;

        }

        $this->logger->markRunning($log);

        $export = $this->scraper->exportManufacturerInstockMerged(manufacturerId: 1);

        if (($export['ok'] ?? false) !== true) {

            $this->logger->fail($log, (string) ($export['error_message'] ?? 'PLAMOD in-stock export failed.'));

            return;

        }

        $csvPath = (string) ($export['csv_storage_path'] ?? '');

        if ($csvPath === '') {

            $this->logger->fail($log, 'PLAMOD in-stock export returned no CSV path.');

            return;

        }

        $rowCount = (int) ($export['row_count'] ?? 0);

        $expectedCount = (int) ($export['expected_row_count'] ?? 0);

        if ($expectedCount > 0 && ($rowCount === 0 || $rowCount < (int) floor($expectedCount * 0.85))) {

            $this->logger->fail(

                $log,

                "PLAMOD in-stock export incomplete: got {$rowCount} rows, expected ~{$expectedCount}. Snapshot not updated.",

            );

            return;

        }

        try {

            $this->logger->progress($log, ['phase' => 'import']);

            $counts = $this->import->importFromStoragePath($csvPath, $syncLogId);

        } catch (\Throwable $e) {

            $this->logger->fail($log, $e->message());

            return;

        }

        $this->logger->complete($log, array_merge($counts, [

            'row_count' => $rowCount > 0 ? $rowCount : (int) $counts['rows_upserted'],

            'expected_row_count' => $expectedCount > 0 ? $expectedCount : null,

            'filter_mode' => $export['filter_mode'] ?? null,

            'filter_chunks' => $export['filter_chunks'] ?? [],

            'csv_storage_path' => $csvPath,

        ]));

    }
}
