<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PlamodPreorderCsvImportService
{
    /**
     * @return array{rows_parsed: int, rows_upserted: int, rows_dropped: int, rows_skipped: int}
     */
    public function importFromStoragePath(string $csvStoragePath): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($csvStoragePath)) {
            throw new \InvalidArgumentException("CSV not found at {$csvStoragePath}");
        }

        $handle = fopen($disk->path($csvStoragePath), 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open preorder CSV.');
        }

        try {
            $header = fgetcsv($handle, escape: '\\');
            if (! is_array($header)) {
                throw new \RuntimeException('Preorder CSV header missing.');
            }

            $map = $this->headerMap($header);
            $now = now();
            $seenSkus = [];
            $parsed = 0;
            $skipped = 0;

            DB::transaction(function () use ($handle, $map, $now, &$seenSkus, &$parsed, &$skipped): void {
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
                    $attributes = [
                        'barcode' => $this->nullableCell($row, $map, 'Barcode'),
                        'product_name' => $this->cell($row, $map, 'Product Name') ?: $sku,
                        'series' => $this->nullableCell($row, $map, 'Series'),
                        'release_date' => $this->parseDate($this->cell($row, $map, 'Release Date')),
                        'manufacturer' => $this->nullableCell($row, $map, 'Manufacturer'),
                        'category' => $this->nullableCell($row, $map, 'Category'),
                        'price_stock' => $this->parseMoney($this->cell($row, $map, 'Price Stock')),
                        'price_preorder' => $this->parseMoney($this->cell($row, $map, 'Price Preorder')),
                        'price_backorder' => $this->parseMoney($this->cell($row, $map, 'Price Backorder')),
                        'quantity_preorder' => $this->parseInt($this->cell($row, $map, 'Quantity Preorder')),
                        'po_due_date' => $this->parseDate($this->cell($row, $map, 'PO Due Date')),
                        'eta_date' => $this->parseDate($this->cell($row, $map, 'ETA Date')),
                        'source_image_url' => $this->nullableCell($row, $map, 'Image URL'),
                        'dropped_at' => null,
                        'last_seen_at' => $now,
                    ];

                    /** @var PlamodPreorder|null $existing */
                    $existing = PlamodPreorder::query()->where('sku', '=', $sku)->first();
                    if ($existing !== null) {
                        foreach ($attributes as $key => $value) {
                            if ($value === null && $existing->getAttribute($key) !== null) {
                                unset($attributes[$key]);
                            }
                        }
                    }

                    PlamodPreorder::query()->updateOrCreate(['sku' => $sku], $attributes);
                }

                if ($seenSkus === []) {
                    return;
                }

                $dropBefore = $now->copy()->subDays(3);
                PlamodPreorder::query()
                    ->whereNull('dropped_at')
                    ->whereNotIn('sku', $seenSkus)
                    ->where(function ($q) use ($dropBefore): void {
                        $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $dropBefore);
                    })
                    ->update(['dropped_at' => $now]);
            });
        } finally {
            fclose($handle);
        }

        $dropped = PlamodPreorder::query()->where('dropped_at', $now)->count();

        return [
            'rows_parsed' => $parsed,
            'rows_upserted' => count($seenSkus),
            'rows_dropped' => $dropped,
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

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^([A-Za-z]{3,9})\s+(\d{1,2})$/', $value, $matches) === 1) {
            return $this->parseMonthDayDate($matches[1], (int) $matches[2]);
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseMonthDayDate(string $month, int $day): ?string
    {
        $year = (int) now()->format('Y');

        try {
            $parsed = Carbon::parse("{$month} {$day} {$year}");
            if ($parsed->isPast()) {
                $parsed = $parsed->addYear();
            }

            return $parsed->toDateString();
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

    private function parseInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
