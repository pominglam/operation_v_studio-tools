<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

final class ShopifyProductSetPayloadValidator
{
    /**
     * Shopify rejects productSet updates that include variants with optionValues but omit productOptions.
     *
     * @param  array<string, mixed>  $productSet
     */
    public static function assertValid(array $productSet): void
    {
        $variants = is_array($productSet['variants'] ?? null) ? $productSet['variants'] : [];
        if ($variants === []) {
            return;
        }

        $variantHasOptionValues = false;
        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $optionValues = $variant['optionValues'] ?? null;
            if (is_array($optionValues) && $optionValues !== []) {
                $variantHasOptionValues = true;
                break;
            }
        }

        if (! $variantHasOptionValues) {
            return;
        }

        $productOptions = $productSet['productOptions'] ?? null;
        if (! is_array($productOptions) || $productOptions === []) {
            throw new \InvalidArgumentException(
                'Shopify productSet payload must include productOptions when variants carry optionValues.',
            );
        }
    }
}
