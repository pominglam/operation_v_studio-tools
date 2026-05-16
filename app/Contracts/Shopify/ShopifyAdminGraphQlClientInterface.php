<?php

declare(strict_types=1);

namespace App\Contracts\Shopify;

interface ShopifyAdminGraphQlClientInterface
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> Decoded GraphQL response body (typically includes `data` / `errors` keys).
     */
    public function query(string $graphql, array $variables = []): array;
}
