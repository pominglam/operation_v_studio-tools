<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\ExportPlamodPreorderHubCsvJob;
use App\Jobs\Plamod\FinalizePlamodPreorderSyncJob;
use App\Models\PlamodPreorderSyncLog;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class PlamodPreorderSyncOrchestrator
{
    public const string QUEUE = 'plamod_sync';

    public function __construct(
        private readonly PlamodPreorderSyncLogger $logger,
        private readonly PlamodPreorderManufacturerFilterDiscoverService $filterDiscover,
    ) {}

    public function start(int $syncLogId, bool $resume = false): void
    {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($syncLogId);
        $log = $this->logger->markRunning($log);
        $checkpoint = PlamodPreorderSyncCheckpoint::fromCounts($log->counts_json ?? []);

        if ($resume && $checkpoint['auto_resume_attempt'] > 0) {
            $log = $this->logger->updateCounts($log, [
                'phase' => 'manufacturer_merged',
                'auto_resume_resumed_at' => now()->toIso8601String(),
            ]);
        }

        $jobs = [];
        if ($checkpoint['hub_csv_path'] === null) {
            $jobs[] = new ExportPlamodPreorderHubCsvJob($syncLogId);
        }
        $jobs[] = new FinalizePlamodPreorderSyncJob($syncLogId);

        Bus::chain($jobs)
            ->catch(function (Throwable $exception) use ($syncLogId): void {
                app(PlamodPreorderSyncChainFailureHandler::class)->handle($syncLogId, $exception);
            })
            ->onQueue(self::QUEUE)
            ->dispatch();
    }

    private function discoverFilters(PlamodPreorderSyncLog $log): PlamodPreorderSyncLog
    {
        $log = $this->logger->updateCounts($log, ['phase' => 'discover']);
        $discover = $this->filterDiscover->discover(1);
        $meta = [
            'manufacturer_filters_discovered' => $discover['ok'] ?? false,
            'manufacturer_filters_undecided' => $discover['undecided_count'] ?? null,
            'manufacturer_filters_include' => $discover['include_count'] ?? null,
            'manufacturer_filters_exclude' => $discover['exclude_count'] ?? null,
        ];
        if (($discover['ok'] ?? false) === false) {
            $meta['manufacturer_discover_error'] = (string) ($discover['error_message'] ?? 'Discover failed');
        }

        return $this->logger->updateCounts($log, $meta);
    }
}
