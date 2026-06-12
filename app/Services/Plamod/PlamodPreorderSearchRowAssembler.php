<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Http\Resources\Api\V1\PlamodPreorderResource;
use App\Models\PlamodPreorder;
use Illuminate\Support\Carbon;

final class PlamodPreorderSearchRowAssembler
{
    public function __construct(
        private readonly PlamodPreorderQueryService $query,
    ) {}

    /**
     * @param  array<int, string>  $skus
     * @return array<int, array<string, mixed>>
     */
    public function rowsForSkus(array $skus): array
    {
        $skus = array_values(array_filter(array_map(
            static fn (mixed $sku): string => trim((string) $sku),
            $skus,
        ), static fn (string $sku): bool => $sku !== ''));

        if ($skus === []) {
            return [];
        }

        $catalogSet = array_flip($this->query->catalogSkus());
        $rowsBySku = PlamodPreorder::query()
            ->active()
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy(static fn (PlamodPreorder $row): string => (string) $row->sku);

        $resolved = [];
        foreach ($skus as $sku) {
            $row = $rowsBySku->get($sku);
            if (! $row instanceof PlamodPreorder) {
                continue;
            }

            $resolved[] = $this->toResourceArray($row, $catalogSet, false);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    public function rowFromLiveHit(array $remote): array
    {
        $catalogSet = array_flip($this->query->catalogSkus());
        $sku = trim((string) ($remote['sku'] ?? ''));
        $model = new PlamodPreorder([
            'sku' => $sku,
            'barcode' => $this->nullableString($remote['barcode'] ?? null),
            'product_name' => trim((string) ($remote['product_name'] ?? $sku)),
            'series' => $this->nullableString($remote['series'] ?? null),
            'release_date' => $this->parseDate($this->stringValue($remote['release_date'] ?? null)),
            'manufacturer' => $this->nullableString($remote['manufacturer'] ?? null),
            'category' => $this->nullableString($remote['category'] ?? null),
            'price_stock' => $this->parseMoney($this->stringValue($remote['price_stock'] ?? null)),
            'price_preorder' => $this->parseMoney($this->stringValue($remote['price_preorder'] ?? null)),
            'quantity_preorder' => $this->parseQuantity($remote['quantity_preorder'] ?? null),
            'po_due_date' => $this->parseDate($this->stringValue($remote['po_due_date'] ?? null)),
            'eta_date' => $this->parseDate($this->stringValue($remote['eta_date'] ?? null)),
            'source_image_url' => $this->nullableString($remote['image_url'] ?? null),
            'image_download_status' => PlamodPreorder::IMAGE_STATUS_PENDING,
        ]);

        return $this->toResourceArray($model, $catalogSet, true);
    }

    /**
     * @param  array<int, array{line: string, sku: string, product_name: string, plamod_pdp_url: string}>  $hits
     * @param  array<string, array<string, mixed>>  $remoteByLine
     * @return array<int, array<string, mixed>>
     */
    public function rowsForLiveHits(array $hits, array $remoteByLine): array
    {
        $rows = [];
        foreach ($hits as $hit) {
            $remote = $remoteByLine[$hit['line']] ?? $hit;
            $rows[] = $this->rowFromLiveHit(is_array($remote) ? $remote + $hit : $hit);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, array{line: string, sku: string}>  $matched
     * @param  array<int, array<string, mixed>>  $importedRows
     * @param  array<int, array{line: string, sku: string}>  $plamodOnly
     * @param  array<int, array<string, mixed>>  $liveRows
     * @return array<int, array<string, mixed>>
     */
    public function rowsInLineOrder(
        array $lines,
        array $matched,
        array $importedRows,
        array $plamodOnly,
        array $liveRows,
    ): array {
        $importedBySku = [];
        foreach ($importedRows as $row) {
            if (is_array($row) && is_string($row['sku'] ?? null)) {
                $importedBySku[$row['sku']] = $row;
            }
        }

        $liveByLine = [];
        foreach ($plamodOnly as $index => $hit) {
            $liveByLine[$hit['line']] = $liveRows[$index] ?? null;
        }

        $matchedByLine = [];
        foreach ($matched as $hit) {
            $matchedByLine[$hit['line']] = $importedBySku[$hit['sku']] ?? null;
        }

        $ordered = [];
        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $row = $matchedByLine[$trimmed] ?? $liveByLine[$trimmed] ?? null;
            if (is_array($row)) {
                $ordered[] = $row;
            }
        }

        return $ordered;
    }

    /**
     * @param  array<int, string>  $catalogSet
     * @return array<string, mixed>
     */
    private function toResourceArray(PlamodPreorder $row, array $catalogSet, bool $notInImport): array
    {
        $row->setAttribute('_is_new', ! isset($catalogSet[trim((string) $row->sku)]));
        $row->setAttribute('_not_in_import', $notInImport);

        /** @var array<string, mixed> $resolved */
        $resolved = (new PlamodPreorderResource($row))->resolve();

        return $resolved;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private function parseQuantity(mixed $value): ?int
    {
        $text = $this->stringValue($value);
        if ($text === '' || ! ctype_digit($text)) {
            return null;
        }

        return (int) $text;
    }

    private function parseMoney(string $value): ?string
    {
        $value = trim(str_replace(['$', ','], '', $value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
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
}
