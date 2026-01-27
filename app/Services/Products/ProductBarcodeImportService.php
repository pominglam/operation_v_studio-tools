<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DTOs\Products\ProductBarcodeImportResultDTO;
use App\Models\Product;

final class ProductBarcodeImportService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    public function importFromShipmentCsv(
        string $path,
        bool $apply,
        bool $overwrite = false,
        ?string $fallbackVendor = null,
        int $vendorColOneBased = 7,
        ?int $skuColOneBased = null,
    ): ProductBarcodeImportResultDTO {
        $path = trim($path);
        if ($path === '' || ! is_file($path)) {
            throw new \InvalidArgumentException('CSV file not found.');
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('CSV file is not readable.');
        }

        try {
            $header = fgetcsv($fh);
            if ($header === false) {
                throw new \InvalidArgumentException('CSV is empty.');
            }

            [$idxSku, $idxBarcode, $idxVendor] = $this->resolveIndexes($header, $skuColOneBased);

            $rowsRead = 0;
            $matched = 0;
            $updated = [];
            $skipped = [];
            $missing = [];
            $ambiguous = [];

            while (($row = fgetcsv($fh)) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $rowsRead++;

                $vendor = $this->resolveVendor($row, $idxVendor, $fallbackVendor, $vendorColOneBased);
                $sku = $this->stringAt($row, $idxSku);
                $barcode = $this->normalizeBarcode($this->stringAt($row, $idxBarcode));

                if ($vendor === '' || $sku === '' || $barcode === '') {
                    $skipped[] = [
                        'vendor' => $vendor !== '' ? $vendor : '(missing)',
                        'sku' => $sku !== '' ? $sku : '(missing)',
                        'reason' => 'missing vendor, sku, or barcode',
                    ];
                    continue;
                }

                $candidates = $this->products->findBySkuAndVendor($sku, $vendor);
                if ($candidates->isEmpty() && $vendor !== mb_strtolower($vendor)) {
                    // Safety fallback for inconsistent casing in older data.
                    $candidates = $this->products->findBySkuAndVendor($sku, mb_strtolower($vendor));
                }

                if ($candidates->isEmpty()) {
                    $missing[] = [
                        'vendor' => $vendor,
                        'sku' => $sku,
                        'reason' => 'no product found',
                    ];
                    continue;
                }

                if ($candidates->count() > 1) {
                    $ambiguous[] = [
                        'vendor' => $vendor,
                        'sku' => $sku,
                        'reason' => 'multiple products matched',
                    ];
                    continue;
                }

                /** @var Product $product */
                $product = $candidates->first();
                $matched++;

                $current = $product->barcode !== null ? trim($product->barcode) : null;
                if ($current !== null && $current !== '' && $current === $barcode) {
                    $skipped[] = [
                        'vendor' => $vendor,
                        'sku' => $sku,
                        'reason' => 'barcode already set to same value',
                    ];
                    continue;
                }

                if (! $overwrite && $current !== null && $current !== '') {
                    $skipped[] = [
                        'vendor' => $vendor,
                        'sku' => $sku,
                        'reason' => 'barcode already set (use --overwrite to replace)',
                    ];
                    continue;
                }

                if ($apply) {
                    $product->barcode = $barcode;
                    $this->products->save($product);
                }

                $updated[] = [
                    'vendor' => $vendor,
                    'sku' => $sku,
                    'old' => $current,
                    'new' => $barcode,
                ];
            }
        } finally {
            fclose($fh);
        }

        return new ProductBarcodeImportResultDTO(
            rowsRead: $rowsRead,
            matched: $matched,
            updatedCount: count($updated),
            skippedCount: count($skipped),
            missingCount: count($missing),
            ambiguousCount: count($ambiguous),
            updated: $updated,
            skipped: $skipped,
            missing: $missing,
            ambiguous: $ambiguous,
        );
    }

    /**
     * @param  array<int, string>  $header
     * @return array{0:int,1:int,2:int|null}
     */
    private function resolveIndexes(array $header, ?int $skuColOneBased): array
    {
        $map = $this->buildHeaderMap($header);

        $idxSku = null;
        if (is_int($skuColOneBased) && $skuColOneBased > 0) {
            $idxSku = $skuColOneBased - 1;
        } else {
            $idxSku = $map['司特力型号'] ?? $map['sku'] ?? null;
        }

        $idxBarcode = $map['barcode'] ?? null;
        $idxVendor = $map['vendor'] ?? null;

        if (! is_int($idxSku)) {
            throw new \InvalidArgumentException('CSV header is missing required column (司特力型号 or sku), or provide --sku-col.');
        }
        if (! is_int($idxBarcode)) {
            throw new \InvalidArgumentException('CSV header is missing required column (barcode).');
        }

        return [$idxSku, $idxBarcode, is_int($idxVendor) ? $idxVendor : null];
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function buildHeaderMap(array $header): array
    {
        $out = [];
        foreach ($header as $idx => $raw) {
            $name = trim((string) $raw);
            $name = preg_replace('/^\xEF\xBB\xBF/u', '', $name) ?? $name; // strip UTF-8 BOM
            $key = mb_strtolower($name);
            if ($key !== '') {
                $out[$key] = $idx;
            }
        }

        return $out;
    }

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
    private function resolveVendor(array $row, ?int $idxVendor, ?string $fallbackVendor, int $vendorColOneBased): string
    {
        if (is_int($idxVendor)) {
            $v = $this->stringAt($row, $idxVendor);
            if ($v !== '') {
                return $v;
            }
        }

        if ($vendorColOneBased > 0) {
            $v = $this->stringAt($row, $vendorColOneBased - 1);
            if ($v !== '') {
                return $v;
            }
        }

        return $fallbackVendor !== null ? trim($fallbackVendor) : '';
    }

    private function normalizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return '';
        }

        $barcode = preg_replace('/\s+/', '', $barcode) ?? $barcode;
        if ($barcode === '' || ! preg_match('/^\d{8,20}$/', $barcode)) {
            return '';
        }

        return $barcode;
    }
}

