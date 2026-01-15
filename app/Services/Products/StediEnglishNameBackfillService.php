<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DTOs\Products\StediEnglishNameBackfillResultDTO;
use App\Models\Product;

final class StediEnglishNameBackfillService
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    public function backfillFromShipmentCsv(
        string $path,
        bool $apply,
        string $vendor = 'Stedi',
        string $nameSource = 'english_name',
    ): StediEnglishNameBackfillResultDTO
    {
        $path = trim($path);
        if ($path === '' || ! is_file($path)) {
            throw new \InvalidArgumentException('CSV file not found.');
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('CSV file is not readable.');
        }

        $rowsRead = 0;
        $matched = 0;
        $updated = [];
        $skipped = [];
        $missing = [];

        try {
            $header = fgetcsv($fh);
            if ($header === false) {
                throw new \InvalidArgumentException('CSV is empty.');
            }

            $map = $this->buildHeaderMap($header);
            $idxSku = $map['司特力型号'] ?? null;
            $idxEnglish = $map['english name'] ?? null;
            if (! is_int($idxSku)) {
                throw new \InvalidArgumentException('CSV header is missing required column (司特力型号).');
            }
            if ($nameSource === 'english_name' && ! is_int($idxEnglish)) {
                throw new \InvalidArgumentException('CSV header is missing required column (english name).');
            }

            while (($row = fgetcsv($fh)) !== false) {
                if ($this->isBlankRow($row)) {
                    continue;
                }

                $rowsRead++;

                $sku = $this->stringAt($row, $idxSku);
                $newName = $this->resolveNewName($row, $idxEnglish, $nameSource);

                if ($sku === '' || $newName === '') {
                    $skipped[] = [
                        'sku' => $sku !== '' ? $sku : '(missing)',
                        'reason' => 'missing sku or name',
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
                        'sku' => $sku,
                        'reason' => "no product found for vendor={$vendor}",
                    ];
                    continue;
                }

                /** @var Product $product */
                $product = $candidates->first();
                $matched++;

                $current = (string) $product->description;
                $new = trim($newName);
                if ($new === '' || $new === $current) {
                    $skipped[] = [
                        'sku' => $sku,
                        'reason' => 'name empty or same as current',
                    ];
                    continue;
                }

                if ($apply) {
                    $product->description = $new;
                    $this->products->save($product);
                }

                $updated[] = [
                    'sku' => $sku,
                    'old' => $current,
                    'new' => $new,
                ];
            }
        } finally {
            fclose($fh);
        }

        return new StediEnglishNameBackfillResultDTO(
            rowsRead: $rowsRead,
            matched: $matched,
            updatedCount: count($updated),
            skippedCount: count($skipped),
            missingCount: count($missing),
            updated: $updated,
            skipped: $skipped,
            missing: $missing,
        );
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
    private function resolveNewName(array $row, ?int $idxEnglish, string $nameSource): string
    {
        if ($nameSource === 'last_non_empty') {
            for ($i = count($row) - 1; $i >= 0; $i--) {
                $v = trim((string) ($row[$i] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }

            return '';
        }

        if ($idxEnglish === null) {
            return '';
        }

        return $this->stringAt($row, $idxEnglish);
    }
}

