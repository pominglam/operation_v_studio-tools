<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\DownloadPlamodPreorderImageJob;
use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderSyncLog;

final class PlamodPreorderSyncFinalizer
{
    public function __construct(
        private readonly PlamodPreorderCsvImportService $importer,
        private readonly PlamodPreorderCsvMergeService $merger,
        private readonly PlamodPreorderImageService $images,
        private readonly PlamodPreorderMissingImageEnrichService $missingImageEnrich,
        private readonly PlamodPreorderSyncLogger $logger,
    ) {}

    public function finalize(int $syncLogId): void
    {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($syncLogId);
        $counts = $log->counts_json ?? [];
        $checkpoint = PlamodPreorderSyncCheckpoint::fromCounts($counts);

        $csvPaths = [];
        if ($checkpoint['hub_csv_path'] !== null) {
            $csvPaths[] = $checkpoint['hub_csv_path'];
        }
        $csvPaths = [...$csvPaths, ...$checkpoint['manufacturer_csv_paths']];
        $csvPaths = array_values(array_unique(array_filter($csvPaths)));

        if ($csvPaths === []) {
            $message = (string) ($counts['hub_export_error'] ?? 'Plamod CSV export produced no sources');
            $this->logger->fail($log, $message);
            throw new \RuntimeException($message);
        }

        $log = $this->logger->updateCounts($log, ['phase' => 'import']);

        $mergedFilename = 'merged-'.now()->format('Ymd-His').'.csv';
        $csvPath = $this->merger->mergeStoragePaths($csvPaths, 'plamod/preorder_exports/'.$mergedFilename);

        $import = $this->importer->importFromStoragePath($csvPath);
        $imagesDeleted = $this->images->cleanupStaleUnlinkedImages();
        $imageEnrich = $this->missingImageEnrich->enrichActiveRowsMissingImageUrl();

        $pendingSkus = PlamodPreorder::query()
            ->active()
            ->whereNotNull('source_image_url')
            ->where(function ($q): void {
                $q->whereNull('image_storage_path')
                    ->orWhere('image_download_status', '!=', PlamodPreorder::IMAGE_STATUS_COMPLETED);
            })
            ->pluck('sku')
            ->all();

        $log = $this->logger->updateCounts($log, [
            'phase' => 'images',
            'rows_parsed' => $import['rows_parsed'],
            'rows_upserted' => $import['rows_upserted'],
            'rows_dropped' => $import['rows_dropped'],
            'rows_skipped' => $import['rows_skipped'],
            'merged_csv_sources' => count($csvPaths),
            'manufacturer_export_succeeded' => (int) ($counts['manufacturer_export_succeeded'] ?? 0),
            'manufacturer_export_failed' => (int) ($counts['manufacturer_export_failed'] ?? 0),
            'manufacturer_export_retried' => (int) ($counts['manufacturer_export_retried'] ?? 0),
            'manufacturer_row_count' => (int) ($counts['manufacturer_row_count'] ?? 0),
            'manufacturer_pull_count' => (int) ($counts['manufacturer_filters_total'] ?? 0),
            'images_deleted' => $imagesDeleted,
            'images_url_enrich_attempted' => $imageEnrich['attempted'],
            'images_url_enrich_enriched' => $imageEnrich['enriched'],
            'images_url_enrich_failed' => $imageEnrich['failed'],
            'images_total' => count($pendingSkus),
            'images_completed' => 0,
            'images_failed' => 0,
            'checkpoint_hub_csv_path' => null,
            'checkpoint_manufacturer_csv_paths' => [],
            'checkpoint_completed_filter_keys' => [],
            'checkpoint_manufacturer_succeeded' => null,
        ]);

        if ($pendingSkus === []) {
            $this->logger->complete($log, ['phase' => 'done']);

            return;
        }

        foreach ($pendingSkus as $sku) {
            DownloadPlamodPreorderImageJob::dispatch($syncLogId, (string) $sku);
        }
    }
}
