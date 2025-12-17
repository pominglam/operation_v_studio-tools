<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductImportRowDTO;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use Illuminate\Http\UploadedFile;

final class ProductImportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTypeDerivationService $types,
    ) {}

    public function import(UploadedFile $file, string $format = 'plamod'): int
    {
        if ($format !== 'plamod') {
            throw new InvalidProductImportFileException('Unknown import format.');
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
                        price: $this->nullableMoneyAt($data, $map['PRICE']),
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
                        price: $this->nullableMoneyAt($data, $map['UNIT PRICE']),
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
