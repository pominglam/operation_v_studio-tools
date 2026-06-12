<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Jobs\Plamod\ExportPlamodManufacturerFilterJob;
use App\Jobs\Plamod\ExportPlamodPreorderHubCsvJob;
use App\Jobs\Plamod\FinalizePlamodPreorderSyncJob;
use App\Jobs\Plamod\RecoverFailedPlamodManufacturerFiltersJob;
use App\Models\PlamodPreorderManufacturerFilter;
use App\Models\PlamodPreorderSyncLog;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class PlamodPreorderSyncOrchestrator
{
    public const string QUEUE = 'plamod_sync';

    public function __construct(
        private readonly PlamodPreorderSyncLogger $logger,
        private readonly PlamodPreorderManufacturerFilterDiscoverService $filterDiscover,
        private readonly PlamodPreorderManufacturerFilterService $filterService,
    ) {}

    public function start(int $syncLogId, bool $resume = false): void
    {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($syncLogId);
        $log = $this->logger->markRunning($log);
        $checkpoint = PlamodPreorderSyncCheckpoint::fromCounts($log->counts_json ?? []);

        if (! $resume) {
            $log = $this->logger->updateCounts($log, ['phase' => 'discover']);
            $discover = $this->filterDiscover->discover(1);
            $discoverMeta = [
                'manufacturer_filters_discovered' => $discover['ok'] ?? false,
                'manufacturer_filters_undecided' => $discover['undecided_count'] ?? null,
                'manufacturer_filters_include' => $discover['include_count'] ?? null,
                'manufacturer_filters_exclude' => $discover['exclude_count'] ?? null,
            ];
            if (($discover['ok'] ?? false) === false) {
                $discoverMeta['manufacturer_discover_error'] = (string) ($discover['error_message'] ?? 'Discover failed');
            }
            $log = $this->logger->updateCounts($log, $discoverMeta);
        } elseif ($checkpoint['auto_resume_attempt'] > 0) {
            $log = $this->logger->updateCounts($log, [
                'phase' => 'manufacturer_export',
                'auto_resume_resumed_at' => now()->toIso8601String(),
            ]);
        }

        $included = $this->filterService->includedFilters(1);
        $total = $included->count();
        $remaining = $this->remainingManufacturerFilters($included, $checkpoint['completed_filter_keys']);

        $log = $this->logger->updateCounts($log, [
            'manufacturer_filters_total' => $total,
            'manufacturer_pull_count' => $total,
        ]);

        /** @var array<int, object> $jobs */
        $jobs = [];

        if ($checkpoint['hub_csv_path'] === null) {
            $jobs[] = new ExportPlamodPreorderHubCsvJob($syncLogId);
        }

        $processedOffset = count($checkpoint['completed_filter_keys']);
        foreach ($remaining as $index => $filter) {
            $jobs[] = new ExportPlamodManufacturerFilterJob(
                $syncLogId,
                (int) $filter->id,
                $processedOffset + $index + 1,
                $total,
            );
        }

        $jobs[] = new RecoverFailedPlamodManufacturerFiltersJob($syncLogId);
        $jobs[] = new FinalizePlamodPreorderSyncJob($syncLogId);

        Bus::chain($jobs)
            ->catch(function (Throwable $exception) use ($syncLogId): void {
                app(PlamodPreorderSyncChainFailureHandler::class)->handle($syncLogId, $exception);
            })
            ->onQueue(self::QUEUE)
            ->dispatch();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PlamodPreorderManufacturerFilter>  $included
     * @param  array<int, string>  $completedFilterKeys
     * @return \Illuminate\Support\Collection<int, PlamodPreorderManufacturerFilter>
     */
    private function remainingManufacturerFilters($included, array $completedFilterKeys)
    {
        if ($completedFilterKeys === []) {
            return $included;
        }

        return $included->filter(
            static fn (PlamodPreorderManufacturerFilter $filter): bool => ! in_array(
                PlamodPreorderSyncCheckpoint::filterKey($filter),
                $completedFilterKeys,
                true,
            ),
        )->values();
    }
}
