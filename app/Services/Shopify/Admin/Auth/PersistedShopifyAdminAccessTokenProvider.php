<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Contracts\Shopify\ShopifyAdminAccessTokenProviderInterface;
use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Models\Shopify\ShopifyOauthInstallation;

final class PersistedShopifyAdminAccessTokenProvider implements ShopifyAdminAccessTokenProviderInterface
{
    public function currentAccessToken(): string
    {
        $configured = ShopifyShopDomainNormalizer::normalize(config('shopify.store_domain'));
        if ($configured === '' || ! ShopifyShopDomainNormalizer::isValidShopifyHost($configured)) {
            throw new ShopifyAdminConfigurationException(
                'SHOPIFY_STORE_DOMAIN must be configured as `{shop}.myshopify.com` for Admin API requests.',
            );
        }

        /** @var ShopifyOauthInstallation|null $row */
        $row = ShopifyOauthInstallation::query()->where('shop_domain', $configured)->first();
        $tokenPlain = trim((string) ($row?->access_token));

        if ($tokenPlain === '') {
            try {
                $hint = route('shopify.oauth.install');
            } catch (\Throwable) {
                $hint = url('/shopify/oauth/install');
            }

            throw new ShopifyAdminConfigurationException(sprintf(
                'No persisted Shopify Admin token for `%s`. Finish OAuth starting at `%s`.',
                $configured,
                $hint,
            ));
        }

        return $tokenPlain;
    }
}
