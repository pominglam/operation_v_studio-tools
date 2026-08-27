<?php

declare(strict_types=1);

namespace App\Support\Products;

final class AgentTestSkuGuard
{
    public static function isAgentTestSku(string $sku, string $description = ''): bool
    {
        $skuUpper = strtoupper(trim($sku));
        $descriptionUpper = strtoupper(trim($description));

        return str_starts_with($skuUpper, 'E2E-')
            || str_starts_with($skuUpper, 'ARCH-UI-')
            || str_starts_with($skuUpper, 'BR-UI-')
            || str_contains($skuUpper, 'UI-TEST')
            || str_contains($descriptionUpper, 'UI TEST KIT')
            || str_contains($descriptionUpper, 'E2E RESTOCK')
            || str_contains($descriptionUpper, 'E2E PRODUCT')
            || str_contains($descriptionUpper, 'E2E PO PRODUCT')
            || str_contains($descriptionUpper, 'E2E MANUAL UPLOAD');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Product>  $query
     */
    public static function excludeFromProductQuery($query): void
    {
        $query->where(static function ($productQuery): void {
            $productQuery
                ->where('sku', 'not like', 'E2E-%')
                ->where('sku', 'not like', 'ARCH-UI-%')
                ->where('sku', 'not like', 'BR-UI-%')
                ->where('sku', 'not like', '%UI-TEST%')
                ->whereRaw('UPPER(description) NOT LIKE ?', ['%UI TEST KIT%']);
        });
    }
}
