<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\Products\Exceptions\InvalidProductImportFileException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ProductInventoryImportService
{
    public const string MATCH_COLUMN = 'Variant SKU';
    public const string QTY_COLUMN = 'Variant Inventory Qty';

    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @return array{
     *   updated: int,
     *   match_column: string,
     *   qty_column: string,
     *   backup_before_path: string,
     *   uploaded_file_path: string,
     *   missing_in_system: array<int, string>,
     *   not_updated: array<int, array{id: string, sku: string, description: string, available: int|null}>
     * }
     */
    public function import(UploadedFile $file): array
    {
        // Backup first (before any updates)
        $backupBeforePath = $this->backupCurrentInventory();
        $uploadedFilePath = $this->storeUploadedFile($file);

        $skuToQty = $this->parseShopifyInventoryCsv($file);
        $skusWithQty = array_keys($skuToQty);

        $updated = 0;
        $missingInSystem = [];
        if ($skusWithQty !== []) {
            $existing = $this->products->findBySkus($skusWithQty);
            /** @var array<string, int> $bySku */
            $bySku = $skuToQty;

            $foundSkus = [];
            foreach ($existing as $product) {
                $sku = $product->sku;
                if (! array_key_exists($sku, $bySku)) {
                    continue;
                }

                $foundSkus[] = $sku;
                $product->available_qty = $bySku[$sku];
                $this->products->save($product);
                $updated++;
            }

            $foundSet = array_fill_keys($foundSkus, true);
            foreach ($skusWithQty as $sku) {
                if (! array_key_exists($sku, $foundSet)) {
                    $missingInSystem[] = $sku;
                }
            }
        }

        $notUpdated = $this->notUpdatedProducts($skuToQty);

        return [
            'updated' => $updated,
            'match_column' => self::MATCH_COLUMN,
            'qty_column' => self::QTY_COLUMN,
            'backup_before_path' => $backupBeforePath,
            'uploaded_file_path' => $uploadedFilePath,
            'missing_in_system' => $missingInSystem,
            'not_updated' => $notUpdated,
        ];
    }

    private function storeUploadedFile(UploadedFile $file): string
    {
        $ts = now()->format('Ymd_His');
        $name = "shopify_inventory_{$ts}.csv";

        /** @var string $path */
        $path = $file->storeAs('imports/inventory', $name, 'local');

        return $path;
    }

    private function backupCurrentInventory(): string
    {
        $ts = now()->format('Ymd_His');
        $path = "backups/inventory/before_{$ts}.csv";

        $rows = [];
        $rows[] = ['SKU', 'DESCRIPTION', 'AVAILABLE_QTY'];

        $all = $this->products->listAll()->sortBy('sku')->values();
        foreach ($all as $p) {
            $rows[] = [$p->sku, $p->description, $p->available_qty === null ? '' : (string) $p->available_qty];
        }

        $csv = $this->renderCsv($rows);
        Storage::disk('local')->put($path, $csv);

        return $path;
    }

    /**
     * @return array<string, int> sku => qty
     */
    private function parseShopifyInventoryCsv(UploadedFile $file): array
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
            if (! array_key_exists(self::MATCH_COLUMN, $map)) {
                throw new InvalidProductImportFileException('Missing required column: '.self::MATCH_COLUMN);
            }
            if (! array_key_exists(self::QTY_COLUMN, $map)) {
                throw new InvalidProductImportFileException('Missing required column: '.self::QTY_COLUMN);
            }

            $skuIdx = $map[self::MATCH_COLUMN];
            $qtyIdx = $map[self::QTY_COLUMN];

            $skuToQty = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $sku = $this->stringAt($row, $skuIdx);
                if ($sku === '') {
                    continue;
                }

                $qtyRaw = $this->stringAt($row, $qtyIdx);
                $qtyRaw = trim($qtyRaw);
                if ($qtyRaw === '') {
                    continue;
                }

                if (! preg_match('/^-?\d+(\.\d+)?$/', $qtyRaw)) {
                    continue;
                }

                $qty = (int) round((float) $qtyRaw);
                if ($qty < 0) {
                    continue;
                }

                // last one wins if duplicates exist
                $skuToQty[$sku] = $qty;
            }

            return $skuToQty;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Products not updated because the CSV had no usable qty for their SKU.
     *
     * @param  array<string, int>  $skuToQty
     * @return array<int, array{id: string, sku: string, description: string, available: int|null}>
     */
    private function notUpdatedProducts(array $skuToQty): array
    {
        $have = array_fill_keys(array_keys($skuToQty), true);

        $out = [];
        $all = $this->products->listAll()->sortBy('sku')->values();
        /** @var Product $p */
        foreach ($all as $p) {
            if (array_key_exists($p->sku, $have)) {
                continue;
            }

            $out[] = [
                'id' => $p->uuid,
                'sku' => $p->sku,
                'description' => $p->description,
                'available' => $p->available_qty,
            ];
        }

        return $out;
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
            if ($key === '') {
                continue;
            }
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
            if (trim((string) $cell) !== '') {
                return false;
            }
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

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function renderCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new InvalidProductImportFileException('Failed to create backup CSV.');
        }

        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv === false ? '' : $csv;
    }
}


