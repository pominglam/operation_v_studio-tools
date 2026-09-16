<?php

declare(strict_types=1);

namespace App\Support\Plamod;

final class PlamodInstockFilterChunks
{
    /**
     * @param  array<int, mixed>  $chunks
     * @return array<int, array{name: string, tab: string, category_id: string|null, expected: int, rows: int, error: string|null}>
     */
    public static function failed(array $chunks): array
    {
        $failed = [];
        foreach ($chunks as $chunk) {
            if (! is_array($chunk) || ! self::isFailed($chunk)) {
                continue;
            }

            $name = trim((string) ($chunk['filter'] ?? $chunk['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $error = trim((string) ($chunk['error'] ?? ''));
            $failed[] = [
                'name' => $name,
                'tab' => trim((string) ($chunk['tab'] ?? 'BRAND')) ?: 'BRAND',
                'category_id' => self::nullableString($chunk['category_id'] ?? null),
                'expected' => (int) ($chunk['listing_expected'] ?? $chunk['expected'] ?? 0),
                'rows' => (int) ($chunk['rows'] ?? 0),
                'error' => $error !== '' ? $error : null,
            ];
        }

        return $failed;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    public static function isFailed(array $chunk): bool
    {
        if (trim((string) ($chunk['error'] ?? '')) !== '') {
            return true;
        }

        if (($chunk['skipped'] ?? false) === true) {
            return true;
        }

        $expected = (int) ($chunk['listing_expected'] ?? $chunk['expected'] ?? 0);
        $rows = (int) ($chunk['rows'] ?? 0);

        return $expected > 0 && $rows === 0;
    }

    /**
     * @param  array<int, mixed>  $previous
     * @param  array<int, mixed>  $retry
     * @return array<int, mixed>
     */
    public static function merge(array $previous, array $retry): array
    {
        $merged = $previous;
        foreach ($retry as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }
            $key = self::key($chunk);
            $replaced = false;
            foreach ($merged as $index => $existing) {
                if (is_array($existing) && self::key($existing) === $key) {
                    $merged[$index] = $chunk;
                    $replaced = true;
                    break;
                }
            }
            if (! $replaced) {
                $merged[] = $chunk;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    public static function key(array $chunk): string
    {
        $tab = trim((string) ($chunk['tab'] ?? 'BRAND')) ?: 'BRAND';
        $name = trim((string) ($chunk['filter'] ?? $chunk['name'] ?? ''));

        return $tab."\t".$name;
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
