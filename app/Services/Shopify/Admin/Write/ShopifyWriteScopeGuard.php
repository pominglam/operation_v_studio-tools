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
        return $this->hasScope('write_products');
    }

    public function assertWriteInventoryScope(): void
    {
        if (! $this->hasWriteInventoryScope()) {
            throw new ShopifyAdminConfigurationException(
                'Shopify OAuth token is missing write_inventory scope. Re-install the app with write_inventory in SHOPIFY_OAUTH_SCOPES, then complete OAuth again.',
            );
        }
    }

    public function hasWriteInventoryScope(): bool
    {
        return $this->hasScope('write_inventory');
    }

    public function assertWritePublicationsScope(): void
    {
        if (! $this->hasWritePublicationsScope()) {
            throw new ShopifyAdminConfigurationException(
                'Shopify OAuth token is missing write_publications scope. Re-install the app with write_publications in SHOPIFY_OAUTH_SCOPES, then complete OAuth again.',
            );
        }
    }

    public function hasWritePublicationsScope(): bool
    {
        return $this->hasScope('write_publications');
    }

    public function hasReadPublicationsScope(): bool
    {
        return $this->hasScope('read_publications');
    }

    private function hasScope(string $scope): bool
    {
        /** @var string|null $scopesRaw */
        $scopesRaw = config('shopify.oauth_scopes');
        if (! is_string($scopesRaw) || trim($scopesRaw) === '') {
            return false;
        }

        $scopes = array_map(
            static fn (string $scopeName): string => strtolower(trim($scopeName)),
            explode(',', $scopesRaw),
        );

        return in_array(strtolower($scope), $scopes, true);
    }
}
