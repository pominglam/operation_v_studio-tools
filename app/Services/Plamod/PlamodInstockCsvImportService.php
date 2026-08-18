<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodInstockItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PlamodInstockCsvImportService
{
    /**
     * @return array{rows_parsed: int, rows_upserted: int, rows_skipped: int}
     */
    public function importFromStoragePath(string $csvStoragePath, int $syncLogId): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($csvStoragePath)) {
            throw new \InvalidArgumentException("CSV not found at {$csvStoragePath}");
        }

        $handle = fopen($disk->path($csvStoragePath), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open in-stock CSV.');
        }

        try {
            $header = fgetcsv($handle, escape: '\\');
            if (! is_array($header)) {
                throw new \RuntimeException('In-stock CSV header missing.');
            }

            $map = $this->headerMap($header);
            $now = now();
            $seenSkus = [];
            $parsed = 0;
            $skipped = 0;
            $baseUrl = rtrim((string) config('services.plamod.base_url', 'https://plamod.com'), '/');

            DB::transaction(function () use ($handle, $map, $now, $syncLogId, $baseUrl, &$seenSkus, &$parsed, &$skipped): void {
                while (($row = fgetcsv($handle, escape: '\\')) !== false) {
                    if (! is_array($row) || $this->rowIsEmpty($row)) {
                        continue;
                    }

                    $parsed++;
                    $sku = $this->cell($row, $map, 'SKU');
                    if (! PlamodPreorderSkuValidator::isValid($sku)) {
                        $skipped++;

                        continue;
                    }

                    $seenSkus[] = $sku;
                    $releaseRaw = $this->cell($row, $map, 'Release Date');
                    $releaseDate = $this->parseReleaseDate($releaseRaw);

                    PlamodInstockItem::query()->updateOrCreate(['sku' => $sku], [
                        'barcode' => $this->nullableCell($row, $map, 'Barcode'),
                        'product_name' => $this->cell($row, $map, 'Product Name') ?: $sku,
                        'series' => $this->nullableCell($row, $map, 'Series'),
                        'release_date' => $releaseDate,
                        'release_date_label' => $releaseRaw !== '' ? $releaseRaw : null,
                        'manufacturer' => $this->nullableCell($row, $map, 'Manufacturer'),
                        'category' => $this->nullableCell($row, $map, 'Category'),
                        'price_stock' => $this->parseMoney($this->cell($row, $map, 'Price Stock')),
                        'source_image_url' => $this->nullableCell($row, $map, 'Image URL'),
                        'plamod_pdp_url' => "{$baseUrl}/retailer/products/{$sku}",
                        'last_seen_at' => $now,
                        'sync_log_id' => $syncLogId,
                    ]);
                }

                if ($seenSkus !== []) {
                    PlamodInstockItem::query()
                        ->whereNotIn('sku', $seenSkus)
                        ->delete();
                }
            });
        } finally {
            fclose($handle);
        }

        return [
            'rows_parsed' => $parsed,
            'rows_upserted' => count($seenSkus),
            'rows_skipped' => $skipped,
        ];
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $name) {
            if (! is_string($name)) {
                continue;
            }
            $map[trim($name)] = (int) $idx;
        }

        return $map;
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $map
     */
    private function cell(array $row, array $map, string $column): string
    {
        if (! isset($map[$column])) {
            return '';
        }

        $v = $row[$map[$column]] ?? '';

        return trim(is_string($v) ? $v : (string) $v);
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $map
     */
    private function nullableCell(array $row, array $map, string $column): ?string
    {
        $v = $this->cell($row, $map, $column);

        return $v !== '' ? $v : null;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseReleaseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^([A-Za-z]{3,9})\s+(\d{4})$/', $value, $matches) === 1) {
            try {
                return Carbon::parse("{$matches[1]} 1 {$matches[2]}")->startOfMonth()->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseMoney(string $value): ?string
    {
        $value = trim(str_replace(['$', ','], '', $value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
