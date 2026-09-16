<?php

declare(strict_types=1);

namespace App\Support\Products\Storefront;

use App\Models\Product;

final class MiscStorefrontTagResolver
{
    public function __construct(
        private readonly ModelKitStorefrontTagResolver $modelKitTagResolver = new ModelKitStorefrontTagResolver,
    ) {}

    /**
     * Absolute miscellaneous taxonomy tags from ERP canonical columns.
     *
     * @return array<int, string>
     */
    public function tagsForProduct(Product $product): array
    {
        if ($this->isActionBase($product)) {
            return [];
        }

        if ($this->modelKitTagResolver->tagsForProduct($product) !== []) {
            return [];
        }

        $department = StorefrontTag::slugify(is_string($product->department) ? $product->department : null);
        $mainType = mb_strtolower(trim((string) $product->main_type));
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));
        $productLine = StorefrontTag::slugify(is_string($product->product_line) ? $product->product_line : null);

        if ($department !== 'misc' && $department !== 'figures' && $mainType !== 'misc') {
            return [];
        }

        $tags = [StorefrontTag::MISC_DEPT];

        if ($this->isCcsToys($product, $type, $productLine, $department)) {
            $tags[] = StorefrontTag::MISC_LINE_CCS_TOYS;

            return array_values(array_unique($tags));
        }

        if ($type === 'KEYCHAIN' || $productLine === 'keychains') {
            $tags[] = StorefrontTag::MISC_LINE_KEYCHAIN;

            return array_values(array_unique($tags));
        }

        $tags[] = StorefrontTag::MISC_LINE_OTHER;

        return array_values(array_unique($tags));
    }

    private function isActionBase(Product $product): bool
    {
        $type = mb_strtoupper(trim((string) ($product->type ?? '')));

        return $type === 'ACTION BASE'
            || trim((string) ($product->accessory_kind ?? '')) === 'display_stand';
    }

    private function isCcsToys(Product $product, string $type, ?string $productLine, ?string $department): bool
    {
        if ($department === 'figures' && $productLine === 'ccs_toys') {
            return true;
        }

        if ($type === 'CCS TOYS' || $productLine === 'ccs_toys') {
            return true;
        }

        $sku = mb_strtoupper(trim((string) $product->sku));

        return str_starts_with($sku, 'CCS');
    }
}
