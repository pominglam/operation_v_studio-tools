<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodPreorderManufacturerFilterType;
use App\Models\PlamodPreorderManufacturerFilter;
use App\Services\Products\Http\PlamodScraper;
use Illuminate\Support\Collection;

final class PlamodPreorderExportRetryService
{
    private const int HUB_MAX_ATTEMPTS = 2;

    private const int MANUFACTURER_MAX_ATTEMPTS = 3;

    private const int MANUFACTURER_RECOVERY_ATTEMPTS = 1;

    public function __construct(
        private readonly PlamodScraper $scraper,
    ) {}

    /**
     * @return array{ok: bool, export: array<string, mixed>, attempts: int}
     */
    public function exportHubCsv(int $syncLogId, PlamodPreorderSyncFailureRecorder $recorder): array
    {
        /** @var array<string, mixed> $last */
        $last = ['ok' => false, 'error_message' => 'Hub export not attempted'];

        for ($attempt = 1; $attempt <= self::HUB_MAX_ATTEMPTS; $attempt++) {
            $started = microtime(true);
            try {
                $last = $this->scraper->exportPreordersCsv();
            } catch (\Throwable $exception) {
                $last = [
                    'ok' => false,
                    'error_message' => $exception->getMessage(),
                ];
            }
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if (($last['ok'] ?? false) && ($last['csv_storage_path'] ?? '') !== '') {
                return ['ok' => true, 'export' => $last, 'attempts' => $attempt];
            }

            $message = (string) ($last['error_message'] ?? 'Hub preorder CSV export failed');
            $this->recordFailure($recorder, $syncLogId, 'hub_export', $attempt, $message, $durationMs);

            if ($attempt < self::HUB_MAX_ATTEMPTS && PlamodPreorderSyncFailureKind::isRetryable($message)) {
                $this->resetSessions();
                $this->sleepBeforeRetry($attempt);
            }
        }

        return ['ok' => false, 'export' => $last, 'attempts' => self::HUB_MAX_ATTEMPTS];
    }

    /**
     * @param  Collection<int, PlamodPreorderManufacturerFilter>  $included
     * @param  callable(int, int, PlamodPreorderManufacturerFilter, bool, bool, int, int, string|null): void|null  $onProgress
     * @return array{
     *   csv_paths: array<int, string>,
     *   manufacturer_row_count: int,
     *   manufacturer_export_attempted: int,
     *   manufacturer_export_succeeded: int,
     *   manufacturer_export_failed: int,
     *   manufacturer_export_retried: int,
     *   manufacturer_export_errors: array<int, array<string, mixed>>
     * }
     */
    public function exportManufacturerFilters(
        int $syncLogId,
        Collection $included,
        PlamodPreorderSyncFailureRecorder $recorder,
        ?callable $onProgress = null,
    ): array {
        $csvPaths = [];
        $rowCount = 0;
        $attempted = 0;
        $succeeded = 0;
        $retried = 0;
        /** @var array<int, PlamodPreorderManufacturerFilter> $failedFilters */
        $failedFilters = [];
        /** @var array<int, array<string, mixed>> $errors */
        $errors = [];
        $total = $included->count();
        $processed = 0;

        foreach ($included as $filter) {
            $result = $this->exportManufacturerFilter($syncLogId, $filter, $recorder, self::MANUFACTURER_MAX_ATTEMPTS);
            $attempted++;
            $processed++;
            $retried += max(0, $result['attempts'] - 1);

            if ($result['ok']) {
                $succeeded++;
                $csvPaths[] = (string) $result['csv_storage_path'];
                $rowCount += (int) $result['row_count'];
                $this->emitManufacturerProgress(
                    $onProgress,
                    $processed,
                    $total,
                    $filter,
                    true,
                    false,
                    $succeeded,
                    count($errors),
                    (string) $result['csv_storage_path'],
                );

                continue;
            }

            $failedFilters[] = $filter;
            $errors[] = $this->errorPayload(
                $filter,
                (string) $result['error_message'],
                $result['attempts'],
                $result['error_kind'],
            );
            $this->emitManufacturerProgress(
                $onProgress,
                $processed,
                $total,
                $filter,
                false,
                false,
                $succeeded,
                count($errors),
                null,
            );
        }

        if ($failedFilters !== []) {
            $this->resetSessions();
            $this->sleepBeforeRetry(1);

            $recoveryTotal = count($failedFilters);
            $recoveryProcessed = 0;

            foreach ($failedFilters as $filter) {
                $result = $this->exportManufacturerFilter(
                    $syncLogId,
                    $filter,
                    $recorder,
                    self::MANUFACTURER_RECOVERY_ATTEMPTS,
                    'recovery_pass',
                );
                $attempted++;
                $recoveryProcessed++;
                $retried += max(0, $result['attempts']);

                if ($result['ok']) {
                    $succeeded++;
                    $csvPaths[] = (string) $result['csv_storage_path'];
                    $rowCount += (int) $result['row_count'];
                    $errors = $this->removeErrorForFilter($errors, $filter);
                    $this->emitManufacturerProgress(
                        $onProgress,
                        $recoveryProcessed,
                        $recoveryTotal,
                        $filter,
                        true,
                        true,
                        $succeeded,
                        count($errors),
                        (string) $result['csv_storage_path'],
                    );

                    continue;
                }

                $errors = $this->upsertErrorForFilter(
                    $errors,
                    $this->errorPayload(
                        $filter,
                        (string) $result['error_message'],
                        $result['attempts'],
                        $result['error_kind'],
                        true,
                    ),
                );
                $this->emitManufacturerProgress(
                    $onProgress,
                    $recoveryProcessed,
                    $recoveryTotal,
                    $filter,
                    false,
                    true,
                    $succeeded,
                    count($errors),
                    null,
                );
            }
        }

        return [
            'csv_paths' => $csvPaths,
            'manufacturer_row_count' => $rowCount,
            'manufacturer_export_attempted' => $attempted,
            'manufacturer_export_succeeded' => $succeeded,
            'manufacturer_export_failed' => count($errors),
            'manufacturer_export_retried' => $retried,
            'manufacturer_export_errors' => array_values($errors),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   csv_storage_path?: string,
     *   row_count?: int,
     *   error_message: string,
     *   error_kind: string,
     *   attempts: int
     * }
     */
    public function exportSingleManufacturerFilter(
        int $syncLogId,
        PlamodPreorderManufacturerFilter $filter,
        PlamodPreorderSyncFailureRecorder $recorder,
    ): array {
        return $this->exportManufacturerFilter($syncLogId, $filter, $recorder, self::MANUFACTURER_MAX_ATTEMPTS);
    }

    /**
     * @return array{
     *   ok: bool,
     *   csv_storage_path?: string,
     *   row_count?: int,
     *   error_message: string,
     *   error_kind: string,
     *   attempts: int
     * }
     */
    public function exportSingleManufacturerFilterRecovery(
        int $syncLogId,
        PlamodPreorderManufacturerFilter $filter,
        PlamodPreorderSyncFailureRecorder $recorder,
    ): array {
        return $this->exportManufacturerFilter(
            $syncLogId,
            $filter,
            $recorder,
            self::MANUFACTURER_RECOVERY_ATTEMPTS,
            'recovery_pass',
        );
    }

    public function resetSessionsBetweenSteps(): void
    {
        $this->resetSessions();
    }

    /**
     * @param  callable(int, int, PlamodPreorderManufacturerFilter, bool, bool, int, int, string|null): void|null  $onProgress
     */
    private function emitManufacturerProgress(
        ?callable $onProgress,
        int $processed,
        int $total,
        PlamodPreorderManufacturerFilter $filter,
        bool $ok,
        bool $recoveryPass,
        int $succeeded,
        int $failed,
        ?string $csvStoragePath = null,
    ): void {
        if ($onProgress === null) {
            return;
        }

        $onProgress($processed, $total, $filter, $ok, $recoveryPass, $succeeded, $failed, $csvStoragePath);
    }

    /**
     * @return array{
     *   ok: bool,
     *   csv_storage_path?: string,
     *   row_count?: int,
     *   error_message: string,
     *   error_kind: string,
     *   attempts: int
     * }
     */
    private function exportManufacturerFilter(
        int $syncLogId,
        PlamodPreorderManufacturerFilter $filter,
        PlamodPreorderSyncFailureRecorder $recorder,
        int $maxAttempts,
        string $pass = 'primary',
    ): array {
        $message = 'Manufacturer export not attempted';
        $kind = 'other';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $started = microtime(true);
            try {
                $export = $filter->filter_type === PlamodPreorderManufacturerFilterType::CategoryLine
                    ? $this->scraper->exportManufacturerPreordersCsv(1, null, $filter->name)
                    : $this->scraper->exportManufacturerPreordersCsv(1, $filter->name, null);
            } catch (\Throwable $exception) {
                $export = [
                    'ok' => false,
                    'error_message' => $exception->getMessage(),
                ];
            }
            $durationMs = (int) round((microtime(true) - $started) * 1000);

            if (($export['ok'] ?? false) && ($export['csv_storage_path'] ?? '') !== '') {
                return [
                    'ok' => true,
                    'csv_storage_path' => (string) $export['csv_storage_path'],
                    'row_count' => (int) ($export['row_count'] ?? 0),
                    'error_message' => '',
                    'error_kind' => '',
                    'attempts' => $attempt,
                ];
            }

            $message = (string) ($export['error_message'] ?? 'Manufacturer export failed');
            $kind = PlamodPreorderSyncFailureKind::classify($message);
            $this->recordFailure($recorder, $syncLogId, 'manufacturer_export', $attempt, $message, $durationMs, [
                'pass' => $pass,
                'filter_type' => $filter->filter_type->value,
                'filter_name' => $filter->name,
            ]);

            if ($attempt < $maxAttempts && PlamodPreorderSyncFailureKind::isRetryable($message)) {
                $this->resetSessions();
                $this->sleepBeforeRetry($attempt);
            }
        }

        return [
            'ok' => false,
            'error_message' => $message,
            'error_kind' => $kind,
            'attempts' => $maxAttempts,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordFailure(
        PlamodPreorderSyncFailureRecorder $recorder,
        int $syncLogId,
        string $phase,
        int $attempt,
        string $message,
        int $durationMs,
        array $extra = [],
    ): void {
        $recorder->record($syncLogId, $phase, [
            'attempt' => $attempt,
            'retryable' => PlamodPreorderSyncFailureKind::isRetryable($message),
            'error_kind' => PlamodPreorderSyncFailureKind::classify($message),
            'error_message' => $message,
            'duration_ms' => $durationMs,
            ...$extra,
        ]);
    }

    private function resetSessions(): void
    {
        $this->scraper->resetScraperSessions();
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $baseMs = min(8000, 1500 * $attempt);
        usleep(($baseMs + random_int(0, 1000)) * 1000);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(
        PlamodPreorderManufacturerFilter $filter,
        string $message,
        int $attempts,
        string $errorKind,
        bool $recoveryPass = false,
    ): array {
        return [
            'filter_type' => $filter->filter_type->value,
            'name' => $filter->name,
            'error_message' => $message,
            'error_kind' => $errorKind,
            'attempts' => $attempts,
            'recovery_pass' => $recoveryPass,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<int, array<string, mixed>>
     */
    private function removeErrorForFilter(array $errors, PlamodPreorderManufacturerFilter $filter): array
    {
        return array_values(array_filter(
            $errors,
            static fn (array $error): bool => ($error['name'] ?? '') !== $filter->name
                || ($error['filter_type'] ?? '') !== $filter->filter_type->value,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<string, mixed>  $error
     * @return array<int, array<string, mixed>>
     */
    private function upsertErrorForFilter(array $errors, array $error): array
    {
        foreach ($errors as $index => $existing) {
            if (($existing['name'] ?? '') === ($error['name'] ?? '')
                && ($existing['filter_type'] ?? '') === ($error['filter_type'] ?? '')) {
                $errors[$index] = $error;

                return $errors;
            }
        }

        $errors[] = $error;

        return $errors;
    }
}
