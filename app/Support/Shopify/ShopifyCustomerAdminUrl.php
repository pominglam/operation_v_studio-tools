<?php

declare(strict_types=1);

namespace App\Support\Shopify;

final class ShopifyCustomerAdminUrl
{
    public static function forCustomerGid(?string $gid): ?string
    {
        $gid = is_string($gid) ? trim($gid) : '';
        if ($gid === '' || ! str_starts_with($gid, 'gid://shopify/Customer/')) {
            return null;
        }

        $legacyId = substr($gid, strlen('gid://shopify/Customer/'));
        if ($legacyId === '' || ! ctype_digit($legacyId)) {
            return null;
        }

        $domain = config('shopify.store_domain');
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        return 'https://'.strtolower(trim($domain)).'/admin/customers/'.$legacyId;
    }
}
