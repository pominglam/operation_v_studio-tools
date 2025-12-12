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
    ) {
    }

    public function import(UploadedFile $file): int
    {
        $rows = $this->parseCsv($file);

        return $this->products->upsertImportedRows($rows);
    }

    /**
     * @return array<int, ProductImportRowDTO>
     */
    private function parseCsv(UploadedFile $file): array
    {
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

            $map = $this->buildHeaderMap($header);

            $rows = [];
            while (($data = fgetcsv($handle)) !== false) {
                if ($this->isBlankRow($data)) {
                    continue;
                }

                $sku = $this->stringAt($data, $map['SKU']);
                if ($sku === '') {
                    throw new InvalidProductImportFileException('Missing SKU value.');
                }

                $rows[] = new ProductImportRowDTO(
                    sku: $sku,
                    barcode: $this->nullableStringAt($data, $map['BARCODE']),
                    description: $this->stringAt($data, $map['PRODUCT DESCRIPTION']),
                    type: $this->nullableStringAt($data, $map['TYPE']),
                    price: $this->nullableMoneyAt($data, $map['PRICE']),
                    orderQty: $this->nullableIntAt($data, $map['ORDER']),
                    filledQty: $this->nullableIntAt($data, $map['FILLED']),
                    extended: $this->nullableMoneyAt($data, $map['EXTENDED']),
                );
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, string> $header
     * @return array<string, int>
     */
    private function buildHeaderMap(array $header): array
    {
        $normalized = [];

        foreach ($header as $idx => $name) {
            $key = $this->normalizeHeader((string) $name);
            if ($key !== '') {
                $normalized[$key] = $idx;
            }
        }

        $required = [
            'SKU',
            'BARCODE',
            'PRODUCT DESCRIPTION',
            'TYPE',
            'PRICE',
            'ORDER',
            'FILLED',
            'EXTENDED',
        ];

        foreach ($required as $col) {
            if (! array_key_exists($col, $normalized)) {
                throw new InvalidProductImportFileException("Missing required column: {$col}");
            }
        }

        return $normalized;
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return mb_strtoupper($value);
    }

    /**
     * @param array<int, string> $row
     */
    private function stringAt(array $row, int $idx): string
    {
        return trim((string) ($row[$idx] ?? ''));
    }

    /**
     * @param array<int, string> $row
     */
    private function nullableStringAt(array $row, int $idx): ?string
    {
        $value = $this->stringAt($row, $idx);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, string> $row
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
     * @param array<int, string> $row
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
     * @param array<int, string> $row
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


