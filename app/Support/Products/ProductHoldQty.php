<?php

declare(strict_types=1);

namespace App\Support\Products;

use App\Models\Product;

final class ProductHoldQty
{
    public static function normalized(?int $holdQty): int
    {
        return max(0, (int) ($holdQty ?? 0));
    }

    public static function sellableFromAvailable(?int $availableQty, ?int $holdQty): int
    {
        $available = max(0, (int) ($availableQty ?? 0));

        return max(0, $available - self::normalized($holdQty));
    }

    public static function sellableForProduct(Product $product): int
    {
        return self::sellableFromAvailable($product->available_qty, $product->hold_qty);
    }

    public static function erpAvailableFromShopifyQty(int $shopifyQty, ?int $holdQty): int
    {
        return max(0, max(0, $shopifyQty) + self::normalized($holdQty));
    }

    public static function assertHoldWithinAvailable(?int $holdQty, ?int $availableQty): void
    {
        $hold = self::normalized($holdQty);
        $available = max(0, (int) ($availableQty ?? 0));
        if ($hold > $available) {
            throw new InvalidProductHoldQtyException(
                "Hold quantity ({$hold}) cannot exceed available quantity ({$available}).",
            );
        }
    }
}
