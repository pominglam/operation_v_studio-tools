<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductSellingPriceRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductImportRowDTO;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use App\Services\Products\Exceptions\ProductImportConflictsException;
use Illuminate\Http\UploadedFile;

final class ProductImportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductSellingPriceRepository $sellingPrices,
        private readonly ProductTypeDerivationService $types,
    ) {}

    public function import(UploadedFile $file, string $format = 'plamod'): int
    {
        if (! in_array($format, ['plamod', 'stedi'], true)) {
            throw new InvalidProductImportFileException('Unknown import format.');
        }

        if ($format === 'stedi') {
            $parsed = $this->parseStediCsv($file);
            $this->assertNoStediConflicts($parsed);

            $imported = $this->products->upsertImportedRows($parsed['rows']);
            $this->upsertStediSellingPrices($parsed);

            return $imported;
        }

        $rows = $this->parseCsv($file, $format);

        return $this->products->upsertImportedRows($rows);
    }

    /**
     * @return array<int, ProductImportRowDTO>
     */
    private function parseCsv(UploadedFile $file, string $format): array
    {
        $vendor = $format === 'plamod' ? 'Plamod' : null;

        $path = $file->getRealPath();
        if ($path === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new InvalidProductImportFileException('CSV is empty.');
            }

            $schema = $this->buildHeaderSchema($header);
            $map = $schema['map'];
            $schemaName = $schema['schema'];

            $rows = [];
            while (($data = fgetcsv($handle)) !== false) {
                if ($this->isBlankRow($data)) {
                    continue;
                }

                if ($this->isSummaryStart($data)) {
                    break;
                }

                $sku = $this->stringAt($data, $map['SKU']);
                if ($sku === '') {
                    throw new InvalidProductImportFileException('Missing SKU value.');
                }

                if ($schemaName === 'catalog') {
                    $description = $this->stringAt($data, $map['PRODUCT DESCRIPTION']);
                    $type = $this->nullableStringAt($data, $map['TYPE']);
                    if ($type === null) {
                        $type = $this->types->deriveFromName($description);
                        if ($type === null) {
                            $type = 'Others';
                        }
                    }

                    $rows[] = new ProductImportRowDTO(
                        sku: $sku,
                        barcode: $this->nullableStringAt($data, $map['BARCODE']),
                        description: $description,
                        type: $type,
                        vendor: $vendor,
                        latestUnitCost: $this->nullableMoneyAt($data, $map['PRICE']),
                        orderQty: $this->nullableIntAt($data, $map['ORDER']),
                        filledQty: $this->nullableIntAt($data, $map['FILLED']),
                        extended: $this->nullableMoneyAt($data, $map['EXTENDED']),
                    );
                } else {
                    $name = $this->stringAt($data, $map['PRODUCT NAME']);
                    $type = $this->types->deriveFromName($name) ?? 'Others';
                    $rows[] = new ProductImportRowDTO(
                        sku: $sku,
                        barcode: $this->nullableStringAt($data, $map['BARCODE']),
                        description: $name,
                        type: $type,
                        vendor: $vendor,
                        latestUnitCost: $this->nullableMoneyAt($data, $map['UNIT PRICE']),
                        orderQty: $this->nullableIntAt($data, $map['QTY ORDERED']),
                        filledQty: $this->nullableIntAt($data, $map['QTY FILLED']),
                        extended: $this->nullableMoneyAt($data, $map['LINE SUBTOTAL (BEFORE TAX)']),
                    );
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $header
     * @return array{schema: 'catalog'|'order_details', map: array<string, int>}
     */
    private function buildHeaderSchema(array $header): array
    {
        $normalized = [];

        foreach ($header as $idx => $name) {
            $key = $this->normalizeHeader((string) $name);
            if ($key !== '') {
                $normalized[$key] = $idx;
            }
        }

        $normalized = $this->applyHeaderAliases($normalized);

        $catalogRequired = [
            'SKU',
            'BARCODE',
            'PRODUCT DESCRIPTION',
            'TYPE',
            'PRICE',
            'ORDER',
            'FILLED',
            'EXTENDED',
        ];

        $orderDetailsRequired = [
            'SKU',
            'BARCODE',
            'PRODUCT NAME',
            'QTY ORDERED',
            'QTY FILLED',
            'UNIT PRICE',
            'LINE SUBTOTAL (BEFORE TAX)',
        ];

        if ($this->hasAllColumns($normalized, $catalogRequired)) {
            return [
                'schema' => 'catalog',
                'map' => $normalized,
            ];
        }

        if ($this->hasAllColumns($normalized, $orderDetailsRequired)) {
            return [
                'schema' => 'order_details',
                'map' => $normalized,
            ];
        }

        // Preserve existing error message semantics: report the first missing column from the catalog format.
        foreach ($catalogRequired as $col) {
            if (! array_key_exists($col, $normalized)) {
                throw new InvalidProductImportFileException("Missing required column: {$col}");
            }
        }

        throw new InvalidProductImportFileException('Missing required columns.');
    }

    /**
     * @return array{
     *   rows: array<int, ProductImportRowDTO>,
     *   multipliersBySku: array<string, string>
     * }
     */
    private function parseStediCsv(UploadedFile $file): array
    {
        $vendor = 'Stedi';

        $path = $file->getRealPath();
        if ($path === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidProductImportFileException('Uploaded file is not readable.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new InvalidProductImportFileException('CSV is empty.');
            }

            $schema = $this->buildStediHeaderSchema($header);
            $map = $schema['map'];

            $rows = [];
            $multipliersBySku = [];
            $rowNumbersBySku = [];
            $rowNumbersByBarcode = [];
            $sumOrderQty = 0;

            $totalOrderQty = null;

            $rowNumber = 1; // header row
            while (($data = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($this->isBlankRow($data)) {
                    continue;
                }

                if ($this->isSummaryStart($data)) {
                    break;
                }

                if ($this->isStediTotalRow($data, $map)) {
                    $totalOrderQty = $this->nullableIntAt($data, $map['ORDER QTY']);
                    break;
                }

                $sku = $this->stringAt($data, $map['司特力型号']);
                if ($sku === '') {
                    throw new InvalidProductImportFileException("Missing SKU value (司特力型号) on row {$rowNumber}.");
                }

                $name = $this->stringAt($data, $map['名称']);
                if ($name === '') {
                    throw new InvalidProductImportFileException("Missing 名称 value on row {$rowNumber}.");
                }

                $type = $this->types->deriveFromName($name) ?? 'Others';

                $rows[] = new ProductImportRowDTO(
                    sku: $sku,
                    barcode: $this->nullableStringAt($data, $map['BARCODE']),
                    description: $name,
                    type: $type,
                    vendor: $vendor,
                    latestUnitCost: $this->nullableMoneyAt($data, $map['WHOLESALE PRICE CAD']),
                    orderQty: $this->nullableIntAt($data, $map['ORDER QTY']),
                    filledQty: $this->nullableIntAt($data, $map['QUANTITY RECEIVED']),
                    extended: $this->nullableMoneyAt($data, $map['CAD']),
                );
                $last = $rows[count($rows) - 1];
                $sumOrderQty += (int) ($last->orderQty ?? 0);

                $multiplier = $this->nullableDecimalAt($data, $map['MULTIPLIER']);
                if ($multiplier !== null) {
                    $multipliersBySku[$sku] = $multiplier;
                }

                $rowNumbersBySku[$sku] ??= [];
                $rowNumbersBySku[$sku][] = $rowNumber;

                $barcode = $this->nullableStringAt($data, $map['BARCODE']);
                if ($barcode !== null) {
                    $rowNumbersByBarcode[$barcode] ??= [];
                    $rowNumbersByBarcode[$barcode][] = $rowNumber;
                }
            }

            if ($totalOrderQty !== null && $totalOrderQty !== $sumOrderQty) {
                throw new InvalidProductImportFileException(
                    "Total crosscheck failed: order qty expected {$totalOrderQty}, got {$sumOrderQty}. Import cancelled.",
                );
            }

            return [
                'rows' => $rows,
                'multipliersBySku' => $multipliersBySku,
                'rowNumbersBySku' => $rowNumbersBySku,
                'rowNumbersByBarcode' => $rowNumbersByBarcode,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $map
     */
    private function isStediTotalRow(array $row, array $map): bool
    {
        $idx = $map['WHOLESALE PRICE CAD'] ?? null;
        if (! is_int($idx)) {
            return false;
        }

        $value = $this->stringAt($row, $idx);
        if ($value === '') {
            return false;
        }

        return mb_strtoupper($value) === 'TOTAL AMOUNT';
    }

    /**
     * @param  array{
     *   rows: array<int, ProductImportRowDTO>,
     *   multipliersBySku: array<string, string>,
     *   rowNumbersBySku: array<string, array<int, int>>,
     *   rowNumbersByBarcode: array<string, array<int, int>>
     * } $parsed
     */
    private function assertNoStediConflicts(array $parsed): void
    {
        $issues = [];

        foreach ($parsed['rowNumbersBySku'] as $sku => $rows) {
            if (count($rows) <= 1) {
                continue;
            }

            foreach ($rows as $idx => $rowNum) {
                if ($idx === 0) {
                    continue;
                }
                $issues[] = [
                    'kind' => 'file_duplicate_sku',
                    'row' => $rowNum,
                    'sku' => $sku,
                ];
            }
        }

        foreach ($parsed['rowNumbersByBarcode'] as $barcode => $rows) {
            if (count($rows) <= 1) {
                continue;
            }

            foreach ($rows as $idx => $rowNum) {
                if ($idx === 0) {
                    continue;
                }
                $issues[] = [
                    'kind' => 'file_duplicate_barcode',
                    'row' => $rowNum,
                    'barcode' => $barcode,
                ];
            }
        }

        $skus = array_values(array_unique(array_map(static fn (ProductImportRowDTO $r): string => $r->sku, $parsed['rows'])));
        $barcodes = array_values(array_unique(array_filter(array_map(
            static fn (ProductImportRowDTO $r): string => $r->barcode ?? '',
            $parsed['rows'],
        ), static fn (string $v): bool => trim($v) !== '')));

        $existingBySku = $this->products->findBySkus($skus);
        foreach ($existingBySku as $product) {
            /** @var string $sku */
            $sku = (string) $product->sku;
            $row = $parsed['rowNumbersBySku'][$sku][0] ?? null;
            $issues[] = [
                'kind' => 'db_sku_conflict',
                'row' => $row,
                'sku' => $sku,
                'existing_uuid' => (string) $product->uuid,
            ];
        }

        $existingByBarcode = $this->products->findByBarcodes($barcodes);
        foreach ($existingByBarcode as $product) {
            /** @var string|null $barcode */
            $barcode = $product->barcode;
            if ($barcode === null || trim($barcode) === '') {
                continue;
            }
            $row = $parsed['rowNumbersByBarcode'][$barcode][0] ?? null;
            $issues[] = [
                'kind' => 'db_barcode_conflict',
                'row' => $row,
                'barcode' => $barcode,
                'existing_sku' => (string) $product->sku,
                'existing_uuid' => (string) $product->uuid,
            ];
        }

        if ($issues !== []) {
            throw new ProductImportConflictsException(
                'Import blocked: SKU/barcode conflicts found.',
                $issues,
            );
        }
    }

    /**
     * @param  array{
     *   rows: array<int, ProductImportRowDTO>,
     *   multipliersBySku: array<string, string>,
     *   rowNumbersBySku: array<string, array<int, int>>,
     *   rowNumbersByBarcode: array<string, array<int, int>>
     * } $parsed
     */
    private function upsertStediSellingPrices(array $parsed): void
    {
        if ($parsed['multipliersBySku'] === []) {
            return;
        }

        $skus = array_keys($parsed['multipliersBySku']);
        $products = $this->products->findBySkus($skus);

        foreach ($products as $product) {
            /** @var string $sku */
            $sku = (string) $product->sku;
            $multiplier = $parsed['multipliersBySku'][$sku] ?? null;
            if ($multiplier === null) {
                continue;
            }

            $selling = $this->computeCadSellingPriceX99($product->latest_unit_cost, $multiplier);
            if ($selling === null) {
                continue;
            }

            $this->sellingPrices->upsertForProduct($product, $selling, 'CAD');
        }
    }

    private function computeCadSellingPriceX99(?string $unitCost, string $multiplier): ?string
    {
        $unitCost = $unitCost !== null ? trim($unitCost) : null;
        if ($unitCost === null || $unitCost === '') {
            return null;
        }

        $cents = $this->moneyToCents($unitCost);
        if ($cents <= 0) {
            return null;
        }

        [$num, $den] = $this->decimalToFraction($multiplier);
        if ($num <= 0 || $den <= 0) {
            return null;
        }

        $raw = $cents * $num;
        $baseCents = intdiv($raw + $den - 1, $den); // ceil

        $dollars = intdiv($baseCents, 100);
        $sellingCents = ($dollars * 100) + 99;

        return $this->centsToMoney($sellingCents);
    }

    private function moneyToCents(string $value): int
    {
        $value = trim($value);
        $value = str_replace([',', '$'], '', $value);
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw new InvalidProductImportFileException("Invalid money value: {$value}");
        }

        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }

        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        $cents = ((int) $whole * 100) + (int) $frac;

        return $negative ? -$cents : $cents;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function decimalToFraction(string $value): array
    {
        $value = trim($value);
        $value = str_replace([','], '', $value);
        if ($value === '') {
            return [0, 1];
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            throw new InvalidProductImportFileException("Invalid decimal value: {$value}");
        }

        [$whole, $frac] = array_pad(explode('.', $value, 2), 2, '');
        if ($frac === '') {
            return [(int) $whole, 1];
        }

        $den = 10 ** strlen($frac);
        $num = ((int) $whole * $den) + (int) $frac;

        return [$num, $den];
    }

    private function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);

        $whole = intdiv($cents, 100);
        $frac = $cents % 100;
        $out = $whole.'.'.str_pad((string) $frac, 2, '0', STR_PAD_LEFT);

        return $negative ? '-'.$out : $out;
    }

    /**
     * @param  array<int, string>  $header
     * @return array{schema: 'stedi', map: array<string, int>}
     */
    private function buildStediHeaderSchema(array $header): array
    {
        $normalized = [];

        foreach ($header as $idx => $name) {
            $key = $this->normalizeHeader((string) $name);
            if ($key !== '') {
                $normalized[$key] = $idx;
            }
        }

        $required = [
            '名称',
            '司特力型号',
            'WHOLESALE PRICE CAD',
            'ORDER QTY',
            'CAD',
            'MULTIPLIER',
            'QUANTITY RECEIVED',
            'BARCODE',
        ];

        if ($this->hasAllColumns($normalized, $required)) {
            return [
                'schema' => 'stedi',
                'map' => $normalized,
            ];
        }

        foreach ($required as $col) {
            if (! array_key_exists($col, $normalized)) {
                throw new InvalidProductImportFileException("Missing required column: {$col}");
            }
        }

        throw new InvalidProductImportFileException('Missing required columns.');
    }

    /**
     * @param  array<string, int>  $map
     * @return array<string, int>
     */
    private function applyHeaderAliases(array $map): array
    {
        $aliases = [
            // New friendly names (UI-aligned)
            'NAME' => 'PRODUCT DESCRIPTION',
            'DESCRIPTION' => 'PRODUCT DESCRIPTION',
            'UNIT COST' => 'PRICE',
            'ORDERED' => 'ORDER',
            'SHIPPED' => 'FILLED',
            'TOTAL COST' => 'EXTENDED',

            // Allow Plamod-ish "NAME" to be treated like order-details "PRODUCT NAME" too.
            'NAME (ORDER DETAILS)' => 'PRODUCT NAME',
        ];

        foreach ($aliases as $from => $to) {
            if (! array_key_exists($to, $map) && array_key_exists($from, $map)) {
                $map[$to] = $map[$from];
            }
        }

        return $map;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return mb_strtoupper($value);
    }

    /**
     * @param  array<string, int>  $map
     * @param  array<int, string>  $required
     */
    private function hasAllColumns(array $map, array $required): bool
    {
        foreach ($required as $col) {
            if (! array_key_exists($col, $map)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isSummaryStart(array $row): bool
    {
        $first = trim((string) ($row[0] ?? ''));
        if ($first === '') {
            return false;
        }

        $first = mb_strtoupper($first);

        return in_array($first, ['SUMMARY', 'TOTALS', 'SHIPPING NOTES'], true);
    }

    // Type derivation is handled by ProductTypeDerivationService.

    /**
     * @param  array<int, string>  $row
     */
    private function stringAt(array $row, int $idx): string
    {
        return trim((string) ($row[$idx] ?? ''));
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableStringAt(array $row, int $idx): ?string
    {
        $value = $this->stringAt($row, $idx);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableIntAt(array $row, int $idx): ?int
    {
        $value = $this->stringAt($row, $idx);
        if ($value === '') {
            return null;
        }

        if (! preg_match('/^-?\d+$/', $value)) {
            throw new InvalidProductImportFileException("Invalid integer value: {$value}");
        }

        return (int) $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableMoneyAt(array $row, int $idx): ?string
    {
        $value = $this->stringAt($row, $idx);
        if ($value === '') {
            return null;
        }

        $value = str_replace([',', '$'], '', $value);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $value)) {
            throw new InvalidProductImportFileException("Invalid money value: {$value}");
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function nullableDecimalAt(array $row, int $idx): ?string
    {
        $value = $this->stringAt($row, $idx);
        if ($value === '') {
            return null;
        }

        $value = str_replace([','], '', $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidProductImportFileException("Invalid decimal value: {$value}");
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
