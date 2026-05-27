<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\GraphQl;

final class ShopifyAdminGraphQlMutations
{
    public const string PRODUCT_SET = <<<'GQL'
        mutation productSetCreate($productSet: ProductSetInput!, $synchronous: Boolean!) {
            productSet(synchronous: $synchronous, input: $productSet) {
                product {
                    id
                    handle
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;
}
