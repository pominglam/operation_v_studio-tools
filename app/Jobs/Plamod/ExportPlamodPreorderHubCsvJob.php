<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderExportRetryService;
use App\Services\Plamod\PlamodPreorderSyncFailureRecorder;
use App\Services\Plamod\PlamodPreorderSyncLogger;
use App\Services\Plamod\PlamodPreorderSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExportPlamodPreorderHubCsvJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncLogId,
    ) {
        $this->onQueue(PlamodPreorderSyncOrchestrator::QUEUE);
    }

    public function handle(
        PlamodPreorderExportRetryService $exportRetry,
        PlamodPreorderSyncLogger $logger,
    ): void {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($this->syncLogId);
        $logger->updateCounts($log, ['phase' => 'hub_export']);

        $recorder = new PlamodPreorderSyncFailureRecorder;
        $hubResult = $exportRetry->exportHubCsv($this->syncLogId, $recorder);

        if (($hubResult['ok'] ?? false) !== true) {
            $message = (string) ($hubResult['export']['error_message'] ?? 'Hub preorder CSV export failed');
            $logger->updateCounts($log, [
                'hub_export_error' => $message,
                'hub_export_attempts' => $hubResult['attempts'] ?? 0,
            ]);

            throw new \RuntimeException($message);
        }

        $hubPath = (string) ($hubResult['export']['csv_storage_path'] ?? '');
        $offersPath = (string) ($hubResult['export']['offers_storage_path'] ?? '');
        $logger->updateCounts($log, [
            'checkpoint_hub_csv_path' => $hubPath,
            'checkpoint_offers_path' => $offersPath !== '' ? $offersPath : ($log->counts_json['checkpoint_offers_path'] ?? null),
            'hub_export_attempts' => $hubResult['attempts'] ?? 1,
            'hub_new_preorders_count' => (int) ($hubResult['export']['new_preorders_count'] ?? 0),
            'hub_offer_sheets_count' => (int) ($hubResult['export']['offer_sheets_count'] ?? 0),
            'hub_category_filter' => $hubResult['export']['category_filter'] ?? 'Plastic Model Kits, Figures',
        ]);
    }
}
