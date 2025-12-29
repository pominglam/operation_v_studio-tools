<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use Illuminate\Http\UploadedFile;

final class ProductHandleImportService
{
    public const string SKU_COLUMN = 'Variant SKU';
    public const string HANDLE_COLUMN = 'Handle';

    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @return array{
     *   updated: int,
     *   would_update: int,
     *   match_column: string,
     *   handle_column: string,
     *   uploaded_file_path: string,
     *   missing_in_system: array<int, string>,
     *   missing_sku_rows: int,
     *   missing_handle_rows: int
     * }
     */
    public function import(UploadedFile $file): array
    {
        $uploadedFilePath = $this->storeUploadedFile($file);

        $parsed = $this->parseShopifyHandlesCsv($file);
        $skuToHandle = $parsed['sku_to_handle'];
        $missingSkuRows = $parsed['missing_sku_rows'];
        $missingHandleRows = $parsed['missing_handle_rows'];

        $skus = array_keys($skuToHandle);
        $missingInSystem = [];
        $wouldUpdate = 0;
        $updated = 0;

        if ($skus !== []) {
            $existing = $this->products->findBySkus($skus);
            $foundSkus = [];

            foreach ($existing as $product) {
                $sku = $product->sku;
                if (! array_key_exists($sku, $skuToHandle)) {
                    continue;
                }

                $foundSkus[] = $sku;
                $incoming = $skuToHandle[$sku];
                if ($incoming === '') {
                    continue;
                }

                $wouldUpdate++;
                $product->handle = $incoming;
                $this->products->save($product);
                $updated++;
            }

            $foundSet = array_fill_keys($foundSkus, true);
            foreach ($skus as $sku) {
                if (! array_key_exists($sku, $foundSet)) {
                    $missingInSystem[] = $sku;
                }
            }
        }

        return [
            'updated' => $updated,
            'would_update' => $wouldUpdate,
            'match_column' => self::SKU_COLUMN,
            'handle_column' => self::HANDLE_COLUMN,
            'uploaded_file_path' => $uploadedFilePath,
            'missing_in_system' => $missingInSystem,
            'missing_sku_rows' => $missingSkuRows,
            'missing_handle_rows' => $missingHandleRows,
        ];
    }

    private function storeUploadedFile(UploadedFile $file): string
    {
        $ts = now()->format('Ymd_His');
        $name = "shopify_handles_{$ts}.csv";

        /** @var string $path */
        $path = $file->storeAs('imports/handles', $name, 'local');

        return $path;
    }

    /**
     * @return array{
     *   sku_to_handle: array<string, string>,
     *   missing_sku_rows: int,
     *   missing_handle_rows: int
     * }
     */
    private function parseShopifyHandlesCsv(UploadedFile $file): array
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

            $map = $this->headerMap($header);
            if (! array_key_exists(self::SKU_COLUMN, $map)) {
                throw new InvalidProductImportFileException('Missing required column: '.self::SKU_COLUMN);
            }
            if (! array_key_exists(self::HANDLE_COLUMN, $map)) {
                throw new InvalidProductImportFileException('Missing required column: '.self::HANDLE_COLUMN);
            }

            $skuIdx = $map[self::SKU_COLUMN];
            $handleIdx = $map[self::HANDLE_COLUMN];

            $skuToHandle = [];
            $missingSkuRows = 0;
            $missingHandleRows = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $sku = $this->stringAt($row, $skuIdx);
                if ($sku === '') {
                    $missingSkuRows++;
                    continue;
                }

                $h = $this->stringAt($row, $handleIdx);
                if ($h === '') {
                    $missingHandleRows++;
                    continue;
                }

                // last one wins if duplicates exist
                $skuToHandle[$sku] = $h;
            }

            return [
                'sku_to_handle' => $skuToHandle,
                'missing_sku_rows' => $missingSkuRows,
                'missing_handle_rows' => $missingHandleRows,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function headerMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $name) {
            $key = trim((string) $name);
            if ($key === '') continue;
            $map[$key] = $i;
        }
        return $map;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') return false;
        }
        return true;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function stringAt(array $row, int $idx): string
    {
        if (! array_key_exists($idx, $row)) {
            return '';
        }
        return trim((string) $row[$idx]);
    }
}


