<?php

declare(strict_types=1);

namespace App\Support\Shopify;

final class ShopifyOrderAdminUrl
{
    public static function forLegacyId(?string $legacyId): ?string
    {
        $legacyId = is_string($legacyId) ? trim($legacyId) : '';
        if ($legacyId === '' || ! ctype_digit($legacyId)) {
            return null;
        }

        $domain = config('shopify.store_domain');
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        return 'https://'.strtolower(trim($domain)).'/admin/orders/'.$legacyId;
    }
}
