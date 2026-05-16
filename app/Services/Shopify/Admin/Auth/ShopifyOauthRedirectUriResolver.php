<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;

final class ShopifyOauthRedirectUriResolver
{
    public static function callbackUrlResolved(): string
    {
        $uri = trim((string) config('shopify.oauth_redirect_uri'));
        if ($uri !== '') {
            return $uri;
        }

        $base = rtrim((string) config('app.url'), '/');
        if ($base === '') {
            throw new ShopifyAdminConfigurationException('Set APP_URL or SHOPIFY_OAUTH_REDIRECT_URI for Shopify OAuth redirects.');
        }

        return $base.'/shopify/oauth/callback';
    }
}
