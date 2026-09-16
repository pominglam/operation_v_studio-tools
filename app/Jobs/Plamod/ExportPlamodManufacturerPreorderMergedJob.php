<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderSyncLogger;
use App\Services\Plamod\PlamodPreorderSyncOrchestrator;
use App\Services\Products\Http\PlamodScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExportPlamodManufacturerPreorderMergedJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 10800;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncLogId,
    ) {
        $this->onQueue(PlamodPreorderSyncOrchestrator::QUEUE);
    }

    public function handle(
        PlamodScraper $scraper,
        PlamodPreorderSyncLogger $logger,
    ): void {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($this->syncLogId);
        $logger->updateCounts($log, ['phase' => 'manufacturer_merged']);

        $export = $scraper->exportManufacturerPreorderMerged(1);
        $rowCount = (int) ($export['row_count'] ?? 0);
        $expected = (int) ($export['expected_row_count'] ?? 0);
        $csvPath = trim((string) ($export['csv_storage_path'] ?? ''));
        $ok = ($export['ok'] ?? false) === true;

        if (! $ok) {
            $logger->updateCounts($log, [
                'manufacturer_merged_error' => (string) ($export['error_message'] ?? 'Plamod preorder merged export failed'),
                'manufacturer_row_count' => $rowCount,
                'expected_row_count' => $expected > 0 ? $expected : null,
            ]);
        }

        $paths = is_array($log->refresh()->counts_json['checkpoint_manufacturer_csv_paths'] ?? null)
            ? $log->counts_json['checkpoint_manufacturer_csv_paths']
            : [];
        if ($csvPath !== '' && $rowCount > 0) {
            $paths[] = $csvPath;
        }

        $logger->updateCounts($log, [
            'checkpoint_manufacturer_csv_paths' => array_values(array_unique(array_filter($paths))),
            'checkpoint_offers_path' => (string) ($export['offers_storage_path'] ?? ''),
            'manufacturer_row_count' => $rowCount,
            'expected_row_count' => $expected > 0 ? $expected : null,
            'manufacturer_filter_mode' => $export['filter_mode'] ?? null,
            'manufacturer_filter_chunks' => $export['filter_chunks'] ?? [],
            'manufacturer_export_succeeded' => $ok ? 1 : 0,
            'manufacturer_pull_count' => 1,
        ]);
    }
}
