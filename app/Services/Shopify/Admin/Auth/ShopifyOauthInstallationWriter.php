<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Models\Shopify\ShopifyOauthInstallation;

final class ShopifyOauthInstallationWriter
{
    public function upsertInstallation(string $shopDomain, string $accessTokenPlain, ?string $scopes): ShopifyOauthInstallation
    {
        $shopDomain = ShopifyShopDomainNormalizer::normalize($shopDomain);

        return ShopifyOauthInstallation::query()->updateOrCreate(
            ['shop_domain' => $shopDomain],
            [
                'access_token' => $accessTokenPlain,
                'scopes' => $scopes,
                'oauth_updated_at' => now(),
            ],
        );
    }
}
