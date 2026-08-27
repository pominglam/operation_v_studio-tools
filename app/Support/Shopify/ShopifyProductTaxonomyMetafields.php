<?php

declare(strict_types=1);

namespace App\Support\Shopify;

use App\Models\Product;
use App\Support\Products\ProductTaxonomyFields;

final class ShopifyProductTaxonomyMetafields
{
    public const NAMESPACE = 'ovs_taxonomy';

    /**
     * @return list<array{namespace: string, key: string, type: string, value: string}>
     */
    public static function forProductSet(Product $product): array
    {
        $metafields = [];
        foreach (ProductTaxonomyFields::fromProduct($product) as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }

            if ($key === 'workshop_facets') {
                $metafields[] = [
                    'namespace' => self::NAMESPACE,
                    'key' => $key,
                    'type' => 'json',
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                ];

                continue;
            }

            $metafields[] = [
                'namespace' => self::NAMESPACE,
                'key' => $key,
                'type' => 'single_line_text_field',
                'value' => (string) $value,
            ];
        }

        return $metafields;
    }
}
