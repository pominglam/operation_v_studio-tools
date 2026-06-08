<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\DownloadPlamodPreorderImageJob;
use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderSyncLog;
use App\Services\Products\Http\PlamodScraper;

final class PlamodPreorderSyncService
{
    public function __construct(
        private readonly PlamodScraper $scraper,
        private readonly PlamodPreorderCsvImportService $importer,
        private readonly PlamodPreorderCsvMergeService $merger,
        private readonly PlamodPreorderImageService $images,
        private readonly PlamodPreorderSyncLogger $logger,
    ) {}

    public function run(int $syncLogId): void
    {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($syncLogId);
        $log = $this->logger->markRunning($log);

        try {
            $hubExport = $this->scraper->exportPreordersCsv();
            $bandaiExport = $this->scraper->exportManufacturerPreordersCsv(1);

            $csvPaths = [];
            if (($hubExport['ok'] ?? false) && ($hubExport['csv_storage_path'] ?? '') !== '') {
                $csvPaths[] = (string) $hubExport['csv_storage_path'];
            }

            $manufacturerMeta = [];
            if (($bandaiExport['ok'] ?? false) && ($bandaiExport['csv_storage_path'] ?? '') !== '') {
                $csvPaths[] = (string) $bandaiExport['csv_storage_path'];
                $manufacturerMeta = [
                    'manufacturer_row_count' => $bandaiExport['row_count'] ?? null,
                    'manufacturer_has_vigna_sku' => $bandaiExport['has_vigna_sku'] ?? null,
                    'manufacturer_has_vigna_name' => $bandaiExport['has_vigna_name'] ?? null,
                ];
            } elseif (($bandaiExport['ok'] ?? false) === false) {
                $manufacturerMeta['manufacturer_export_error'] = (string) ($bandaiExport['error_message'] ?? 'Bandai manufacturer export failed');
            }

            if ($csvPaths === []) {
                $message = (string) ($hubExport['error_message'] ?? $bandaiExport['error_message'] ?? 'Plamod CSV export failed');
                throw new \RuntimeException($message);
            }

            $mergedFilename = 'merged-'.now()->format('Ymd-His').'.csv';
            $csvPath = $this->merger->mergeStoragePaths($csvPaths, 'plamod/preorder_exports/'.$mergedFilename);

            $import = $this->importer->importFromStoragePath($csvPath);
            $imagesDeleted = $this->images->cleanupStaleUnlinkedImages();

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
                'merged_csv_sources' => count($csvPaths),
                ...$manufacturerMeta,
                'images_deleted' => $imagesDeleted,
                'images_total' => count($pendingSkus),
                'images_completed' => 0,
                'images_failed' => 0,
            ]);

            if ($pendingSkus === []) {
                $this->logger->complete($log, ['phase' => 'done']);

                return;
            }

            foreach ($pendingSkus as $sku) {
                DownloadPlamodPreorderImageJob::dispatch($syncLogId, (string) $sku);
            }
        } catch (\Throwable $e) {
            $this->logger->fail($log, $e->getMessage());
            throw $e;
        }
    }
}
