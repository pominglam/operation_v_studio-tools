<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Write;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Models\Shopify\ShopifyLocation;

final class ShopifyInventoryLocationResolver
{
    public function resolveLocationGid(): string
    {
        /** @var string|null $configured */
        $configured = config('shopify.inventory_location_gid');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $preferred = ShopifyLocation::query()
            ->where('is_active', '=', true)
            ->where('fulfills_online_orders', '=', true)
            ->orderBy('id')
            ->value('gid');

        if (is_string($preferred) && trim($preferred) !== '') {
            return trim($preferred);
        }

        $fallback = ShopifyLocation::query()
            ->where('is_active', '=', true)
            ->orderBy('id')
            ->value('gid');

        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        throw new ShopifyAdminConfigurationException(
            'No Shopify location available for inventory push. Run `php artisan shopify:sync locations` or set SHOPIFY_INVENTORY_LOCATION_GID.',
        );
    }

    public function resolveLocationLabel(): ?string
    {
        $gid = $this->resolveLocationGid();

        $name = ShopifyLocation::query()
            ->where('gid', '=', $gid)
            ->value('name');

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
