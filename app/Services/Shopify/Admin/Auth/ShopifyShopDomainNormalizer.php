<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

final class ShopifyShopDomainNormalizer
{
    public static function normalize(?string $domain): string
    {
        if (! is_string($domain)) {
            return '';
        }
        $d = strtolower(trim(str_replace(['https://', 'http://'], '', $domain)));
        $d = rtrim($d, '/');

        return $d;
    }

    public static function isValidShopifyHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $host) === 1;
    }
}
