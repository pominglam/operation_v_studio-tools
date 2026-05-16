<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;

final class ShopifyOAuthInstallRedirectBuilder
{
    /**
     * Offline admin token: omit grant_options[]=per-user (Shopify OAuth docs).
     *
     * @throws ShopifyAdminConfigurationException
     */
    public function authorizationRedirectUrl(string $csrfStateNonce): string
    {
        $clientId = trim((string) config('shopify.client_id'));
        if ($clientId === '') {
            throw new ShopifyAdminConfigurationException('Missing SHOPIFY_CLIENT_ID configuration.');
        }

        $shop = ShopifyShopDomainNormalizer::normalize((string) config('shopify.store_domain'));
        if ($shop === '' || ! ShopifyShopDomainNormalizer::isValidShopifyHost($shop)) {
            throw new ShopifyAdminConfigurationException('Set SHOPIFY_STORE_DOMAIN to a valid `{shop}.myshopify.com` hostname.');
        }

        $scopes = trim((string) config('shopify.oauth_scopes'));
        if ($scopes === '') {
            throw new ShopifyAdminConfigurationException('SHOPIFY_OAUTH_SCOPES resolves empty.');
        }

        $redirectUri = ShopifyOauthRedirectUriResolver::callbackUrlResolved();

        $query = http_build_query([
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $csrfStateNonce,
        ], '', '&', PHP_QUERY_RFC3986);

        return sprintf('https://%s/admin/oauth/authorize?%s', $shop, $query);
    }
}
