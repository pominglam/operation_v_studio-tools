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

final class RecoverFailedPlamodManufacturerFiltersJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

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
        $counts = $log->counts_json ?? [];
        /** @var array<int, array<string, mixed>> $errors */
        $errors = is_array($counts['manufacturer_export_errors'] ?? null) ? $counts['manufacturer_export_errors'] : [];

        if ($errors === []) {
            return;
        }

        $logger->updateCounts($log, ['phase' => 'manufacturer_recovery']);
        $exportRetry->resetSessionsBetweenSteps();

        $recorder = new PlamodPreorderSyncFailureRecorder;
        /** @var array<int, string> $paths */
        $paths = is_array($counts['checkpoint_manufacturer_csv_paths'] ?? null) ? $counts['checkpoint_manufacturer_csv_paths'] : [];
        /** @var array<int, string> $completedKeys */
        $completedKeys = is_array($counts['checkpoint_completed_filter_keys'] ?? null) ? $counts['checkpoint_completed_filter_keys'] : [];

        $succeeded = (int) ($counts['manufacturer_export_succeeded'] ?? 0);
        $failed = (int) ($counts['manufacturer_export_failed'] ?? 0);
        $rowCount = (int) ($counts['manufacturer_row_count'] ?? 0);
        $retried = (int) ($counts['manufacturer_export_retried'] ?? 0);
        $remainingErrors = [];

        foreach ($errors as $error) {
            if (($error['recovery_pass'] ?? false) === true) {
                $remainingErrors[] = $error;

                continue;
            }

            $filter = $this->findFilter($error);
            if ($filter === null) {
                $remainingErrors[] = $error;

                continue;
            }

            $result = $exportRetry->exportSingleManufacturerFilterRecovery($this->syncLogId, $filter, $recorder);
            $retried += max(0, $result['attempts']);

            if ($result['ok']) {
                $succeeded++;
                $failed = max(0, $failed - 1);
                $csvPath = (string) ($result['csv_storage_path'] ?? '');
                if ($csvPath !== '' && ! in_array($csvPath, $paths, true)) {
                    $paths[] = $csvPath;
                }
                $filterKey = PlamodPreorderSyncCheckpoint::filterKey($filter);
                if (! in_array($filterKey, $completedKeys, true)) {
                    $completedKeys[] = $filterKey;
                }
                $rowCount += (int) ($result['row_count'] ?? 0);

                continue;
            }

            $remainingErrors[] = [
                'filter_type' => $filter->filter_type->value,
                'name' => $filter->name,
                'error_message' => (string) $result['error_message'],
                'error_kind' => (string) $result['error_kind'],
                'attempts' => (int) $result['attempts'],
                'recovery_pass' => true,
            ];
        }

        $logger->updateCounts($log, [
            'manufacturer_export_succeeded' => $succeeded,
            'manufacturer_export_failed' => count($remainingErrors),
            'manufacturer_export_retried' => $retried,
            'manufacturer_row_count' => $rowCount,
            'manufacturer_export_errors' => $remainingErrors,
            'checkpoint_manufacturer_csv_paths' => $paths,
            'checkpoint_completed_filter_keys' => $completedKeys,
            'checkpoint_manufacturer_succeeded' => count($completedKeys),
        ]);
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function findFilter(array $error): ?PlamodPreorderManufacturerFilter
    {
        $name = trim((string) ($error['name'] ?? ''));
        $type = trim((string) ($error['filter_type'] ?? ''));
        if ($name === '' || $type === '') {
            return null;
        }

        return PlamodPreorderManufacturerFilter::query()
            ->where('manufacturer_id', '=', 1)
            ->where('name', '=', $name)
            ->where('filter_type', '=', $type)
            ->first();
    }
}
