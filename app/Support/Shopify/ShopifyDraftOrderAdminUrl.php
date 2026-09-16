<?php

declare(strict_types=1);

namespace App\Support\Shopify;

final class ShopifyDraftOrderAdminUrl
{
    public static function forLegacyId(?string $legacyId): ?string
    {
        $legacyId = is_string($legacyId) ? trim($legacyId) : '';
        if ($legacyId === '') {
            return null;
        }

        $domain = self::storeDomain();
        if ($domain === null) {
            return null;
        }

        return "https://{$domain}/admin/draft_orders/{$legacyId}";
    }

    private static function storeDomain(): ?string
    {
        $domain = config('shopify.store_domain');
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        return strtolower(trim($domain));
    }
}
