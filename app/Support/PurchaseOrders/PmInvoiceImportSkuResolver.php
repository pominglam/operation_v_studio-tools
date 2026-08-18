<?php

declare(strict_types=1);

namespace App\Support\PurchaseOrders;

use Illuminate\Support\Str;

final class PmInvoiceImportSkuResolver
{
    /**
     * @param  array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null,
     *   pm_customer_ref?:string|null
     * }>  $rows
     * @return array<int, array{
     *   row:int,
     *   sku:string,
     *   unit_cost:string|null,
     *   qty_ordered:int|null,
     *   qty_shipped:int|null,
     *   qty_received:int|null,
     *   product_name:string|null,
     *   barcode:string|null,
     *   vendor_line_total?:string|null
     * }>
     */
    public function normalizeRows(array $rows): array
    {
        $normalized = [];
        $usedSkus = [];

        foreach ($rows as $row) {
            $customerRef = trim((string) ($row['pm_customer_ref'] ?? ''));
            $itemName = $this->normalizeWhitespace(trim((string) ($row['product_name'] ?? '')));
            $sizeCode = trim((string) ($row['sku'] ?? ''));
            $rowNumber = (int) ($row['row'] ?? 0);

            $sku = $this->resolveSku($customerRef, $itemName, $sizeCode, $rowNumber);
            $sku = $this->ensureUniqueSku($sku, $rowNumber, $usedSkus);
            $usedSkus[$sku] = true;
            $productName = $this->resolveProductName($customerRef, $itemName, $sizeCode);

            unset($row['pm_customer_ref']);
            $row['sku'] = $sku;
            $row['product_name'] = $productName;

            $normalized[] = $row;
        }

        return $normalized;
    }

    public function resolveSku(string $customerRef, string $itemName, string $sizeCode, int $rowNumber): string
    {
        if ($this->isNumericPmLineRef($customerRef)) {
            return 'PM-'.$customerRef;
        }

        if ($sizeCode !== '' && ! $this->isPmInvoiceSizeToken($sizeCode)) {
            return $this->capSku($sizeCode);
        }

        if ($itemName !== '') {
            return $this->capSku($this->slugFromItemName($itemName, $sizeCode));
        }

        $suffix = $sizeCode !== '' ? $sizeCode : (string) max(1, $rowNumber);

        return $this->capSku('pm-line-'.Str::slug($suffix, '-'));
    }

    public function resolveProductName(string $customerRef, string $itemName, string $sizeCode): ?string
    {
        $itemName = $this->normalizeWhitespace($itemName);
        $sizeCode = trim($sizeCode);

        if ($itemName !== '') {
            if ($sizeCode !== '' && $this->isPmInvoiceSizeToken($sizeCode) && ! str_contains(strtolower($itemName), strtolower($sizeCode))) {
                return $itemName.' ('.$sizeCode.')';
            }

            return $itemName;
        }

        if ($this->isNumericPmLineRef($customerRef)) {
            return $sizeCode !== ''
                ? sprintf('PM item %s (%s)', $customerRef, $sizeCode)
                : sprintf('PM item %s', $customerRef);
        }

        return null;
    }

    public function isPmInvoiceSizeToken(string $value): bool
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return true;
        }

        if (str_contains($normalized, '-')) {
            return false;
        }

        if (preg_match('/^\d+\/\d+$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z]\d{1,2}$/', $normalized) === 1) {
            return true;
        }

        return strlen($normalized) <= 4 && preg_match('/^[A-Z0-9\/]+$/', $normalized) === 1;
    }

    private function isNumericPmLineRef(string $customerRef): bool
    {
        return preg_match('/^\d+$/', trim($customerRef)) === 1;
    }

    private function slugFromItemName(string $itemName, string $sizeCode): string
    {
        $base = Str::slug($itemName, '-');
        if ($base === '') {
            $base = 'pm-item';
        }

        if ($sizeCode !== '' && $this->isPmInvoiceSizeToken($sizeCode)) {
            $sizeSlug = Str::slug($sizeCode, '-');
            if ($sizeSlug !== '' && ! str_contains($base, $sizeSlug)) {
                return $base.'-'.$sizeSlug;
            }
        }

        return $base;
    }

    private function ensureUniqueSku(string $sku, int $rowNumber, array $usedSkus): string
    {
        $sku = $this->capSku($sku);

        if (! array_key_exists($sku, $usedSkus)) {
            return $sku;
        }

        $suffix = max(1, $rowNumber);
        $candidate = $this->capSku($sku.'-L'.$suffix);
        while (array_key_exists($candidate, $usedSkus)) {
            $suffix++;
            $candidate = $this->capSku($sku.'-L'.$suffix);
        }

        return $candidate;
    }

    private function normalizeWhitespace(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($collapsed) ? $collapsed : trim($value);
    }

    private function capSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '' || strlen($sku) <= 64) {
            return $sku;
        }

        $hash = substr(hash('crc32b', $sku), 0, 8);
        $maxPrefix = 64 - 1 - strlen($hash);
        $prefix = rtrim(substr($sku, 0, $maxPrefix), '-');

        return $prefix.'-'.$hash;
    }
}
