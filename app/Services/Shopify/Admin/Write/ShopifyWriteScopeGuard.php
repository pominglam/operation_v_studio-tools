<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;

final class ShopifyWriteScopeGuard
{
    public function assertWriteProductsScope(): void
    {
        if (! $this->hasWriteProductsScope()) {
            throw new ShopifyAdminConfigurationException(
                'Shopify OAuth token is missing write_products scope. Re-install the app with write_products in SHOPIFY_OAUTH_SCOPES, then complete OAuth again.',
            );
        }
    }

    public function hasWriteProductsScope(): bool
    {
        /** @var string|null $scopesRaw */
        $scopesRaw = config('shopify.oauth_scopes');
        if (! is_string($scopesRaw) || trim($scopesRaw) === '') {
            return false;
        }

        $scopes = array_map(
            static fn (string $scope): string => strtolower(trim($scope)),
            explode(',', $scopesRaw),
        );

        return in_array('write_products', $scopes, true);
    }
}
