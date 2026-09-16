<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class SnaaModelKitTitleResolver
{
    public function resolveTitle(Product $product): ?string
    {
        $current = trim((string) ($product->description ?? ''));
        if ($current === '' || ! $this->isSnaaProduct($product, $current)) {
            return null;
        }

        $next = $this->cleanTitle($current);
        if ($next === $current || $next === '') {
            return null;
        }

        return $next;
    }

    public function cleanTitle(string $title): string
    {
        $stripped = str_replace(["\r\n", "\n", "\r", '\\n'], ' ', $title);
        $stripped = preg_replace('/\b(?:SC|XH|YR)-\d+\b/i', '', $stripped) ?? $stripped;
        $stripped = preg_replace(
            '/\s*\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?\s*x\s*\d+(?:\.\d+)?\s*cm\b(?:\s*\/\s*\d+(?:\.\d+)?\s*(?:k?g))?(?:\s*\(\s*1\s*\/\s*\d+\s*\))?/iu',
            '',
            $stripped,
        ) ?? $stripped;
        $stripped = preg_replace('/\s+-\s+-\s+/', ' - ', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s{2,}/', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+-\s+/', ' - ', $stripped) ?? $stripped;
        $stripped = trim((string) $stripped);
        $stripped = preg_replace('/^SNAA\s+-\s+/i', 'SNAA ', $stripped) ?? $stripped;

        return trim($stripped);
    }

    public function stripModelCode(string $title): string
    {
        return $this->cleanTitle($title);
    }

    public function cleanSku(string $sku): string
    {
        $next = trim($sku);
        $next = preg_replace('/-\d+x\d+x\d+cm-\d+(?:kg|g)-\d+$/i', '', $next) ?? $next;

        return trim($next, '-');
    }

    private function isSnaaProduct(Product $product, string $title): bool
    {
        $line = strtolower(trim((string) ($product->product_line ?? '')));
        $maker = strtolower(trim((string) ($product->manufacturer ?? '')));
        $sku = strtoupper(trim((string) ($product->sku ?? '')));

        return $line === 'snaa'
            || $maker === 'snaa'
            || str_starts_with($sku, 'SNAA-')
            || str_starts_with($sku, 'JS-SNAA')
            || str_contains(strtoupper($title), 'SNAA');
    }
}
