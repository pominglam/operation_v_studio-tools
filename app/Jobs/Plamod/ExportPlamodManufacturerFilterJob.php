<?php

declare(strict_types=1);

namespace App\Jobs\Plamod;

use App\Models\PlamodPreorderManufacturerFilter;
use App\Models\PlamodPreorderSyncLog;
use App\Services\Plamod\PlamodPreorderExportRetryService;
use App\Services\Plamod\PlamodPreorderSyncCheckpoint;
use App\Services\Plamod\PlamodPreorderSyncFailureRecorder;
use App\Services\Plamod\PlamodPreorderSyncLogger;
use App\Services\Plamod\PlamodPreorderSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ExportPlamodManufacturerFilterJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public readonly int $syncLogId,
        public readonly int $filterId,
        public readonly int $sequence,
        public readonly int $total,
    ) {
        $this->onQueue(PlamodPreorderSyncOrchestrator::QUEUE);
    }

    public function handle(
        PlamodPreorderExportRetryService $exportRetry,
        PlamodPreorderSyncLogger $logger,
    ): void {
        /** @var PlamodPreorderSyncLog $log */
        $log = PlamodPreorderSyncLog::query()->findOrFail($this->syncLogId);
        /** @var PlamodPreorderManufacturerFilter $filter */
        $filter = PlamodPreorderManufacturerFilter::query()->findOrFail($this->filterId);

        $recorder = new PlamodPreorderSyncFailureRecorder;
        $result = $exportRetry->exportSingleManufacturerFilter($this->syncLogId, $filter, $recorder);

        $log->refresh();
        $counts = $log->counts_json ?? [];
        /** @var array<int, array<string, mixed>> $errors */
        $errors = is_array($counts['manufacturer_export_errors'] ?? null) ? $counts['manufacturer_export_errors'] : [];
        /** @var array<int, string> $paths */
        $paths = is_array($counts['checkpoint_manufacturer_csv_paths'] ?? null) ? $counts['checkpoint_manufacturer_csv_paths'] : [];
        /** @var array<int, string> $completedKeys */
        $completedKeys = is_array($counts['checkpoint_completed_filter_keys'] ?? null) ? $counts['checkpoint_completed_filter_keys'] : [];

        $succeeded = (int) ($counts['manufacturer_export_succeeded'] ?? 0);
        $failed = (int) ($counts['manufacturer_export_failed'] ?? 0);
        $attempted = (int) ($counts['manufacturer_export_attempted'] ?? 0);
        $rowCount = (int) ($counts['manufacturer_row_count'] ?? 0);
        $retried = (int) ($counts['manufacturer_export_retried'] ?? 0);
        $retried += max(0, $result['attempts'] - 1);
        $attempted++;

        if ($result['ok']) {
            $succeeded++;
            $csvPath = (string) ($result['csv_storage_path'] ?? '');
            if ($csvPath !== '' && ! in_array($csvPath, $paths, true)) {
                $paths[] = $csvPath;
            }
            $filterKey = PlamodPreorderSyncCheckpoint::filterKey($filter);
            if (! in_array($filterKey, $completedKeys, true)) {
                $completedKeys[] = $filterKey;
            }
            $rowCount += (int) ($result['row_count'] ?? 0);
        } else {
            $failed++;
            $errors[] = [
                'filter_type' => $filter->filter_type->value,
                'name' => $filter->name,
                'error_message' => (string) $result['error_message'],
                'error_kind' => (string) $result['error_kind'],
                'attempts' => (int) $result['attempts'],
                'recovery_pass' => false,
            ];
        }

        $logger->updateCounts($log, [
            'phase' => 'manufacturer_export',
            'manufacturer_filters_processed' => $this->sequence,
            'manufacturer_filters_total' => $this->total,
            'manufacturer_current_filter' => $filter->name,
            'manufacturer_current_filter_ok' => $result['ok'],
            'manufacturer_export_succeeded' => $succeeded,
            'manufacturer_export_failed' => $failed,
            'manufacturer_export_attempted' => $attempted,
            'manufacturer_export_retried' => $retried,
            'manufacturer_row_count' => $rowCount,
            'manufacturer_export_errors' => $errors,
            'checkpoint_manufacturer_csv_paths' => $paths,
            'checkpoint_completed_filter_keys' => $completedKeys,
            'checkpoint_manufacturer_succeeded' => count($completedKeys),
        ]);
    }
}
