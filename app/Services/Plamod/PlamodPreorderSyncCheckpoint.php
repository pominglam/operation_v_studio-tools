<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorderManufacturerFilter;
use App\Models\PlamodPreorderSyncLog;

final class PlamodPreorderSyncCheckpoint
{
    public const int MAX_AUTO_RESUME_ATTEMPTS = 5;

    public static function filterKey(PlamodPreorderManufacturerFilter $filter): string
    {
        return $filter->filter_type->value.':'.$filter->name;
    }

    /**
     * @param  array<string, mixed>  $counts
     * @return array{
     *   hub_csv_path: string|null,
     *   manufacturer_csv_paths: array<int, string>,
     *   completed_filter_keys: array<int, string>,
     *   manufacturer_succeeded: int,
     *   auto_resume_attempt: int
     * }
     */
    public static function fromCounts(array $counts): array
    {
        /** @var array<int, string> $manufacturerPaths */
        $manufacturerPaths = is_array($counts['checkpoint_manufacturer_csv_paths'] ?? null)
            ? array_values(array_filter(
                $counts['checkpoint_manufacturer_csv_paths'],
                static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
            ))
            : [];

        /** @var array<int, string> $completedKeys */
        $completedKeys = is_array($counts['checkpoint_completed_filter_keys'] ?? null)
            ? array_values(array_filter(
                $counts['checkpoint_completed_filter_keys'],
                static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
            ))
            : [];

        $hubPath = $counts['checkpoint_hub_csv_path'] ?? null;

        return [
            'hub_csv_path' => is_string($hubPath) && trim($hubPath) !== '' ? trim($hubPath) : null,
            'manufacturer_csv_paths' => $manufacturerPaths,
            'completed_filter_keys' => $completedKeys,
            'manufacturer_succeeded' => (int) ($counts['checkpoint_manufacturer_succeeded'] ?? 0),
            'auto_resume_attempt' => (int) ($counts['auto_resume_attempt'] ?? 0),
        ];
    }

    public static function hasProgress(PlamodPreorderSyncLog $log): bool
    {
        $checkpoint = self::fromCounts($log->counts_json ?? []);

        return $checkpoint['hub_csv_path'] !== null
            || $checkpoint['manufacturer_csv_paths'] !== []
            || (string) ($log->counts_json['phase'] ?? '') !== '';
    }

    public static function isRecoverableFailure(string $message): bool
    {
        $lower = mb_strtolower($message);

        return PlamodPreorderSyncFailureKind::isRetryable($message)
            || str_contains($lower, 'attempted too many times')
            || str_contains($lower, 'maxattempts')
            || str_contains($lower, 'worker')
            || str_contains($lower, 'sigterm')
            || str_contains($lower, 'has timed out');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function mergeIntoCounts(array $counts, array $extra): array
    {
        return array_merge($counts, $extra);
    }
}
