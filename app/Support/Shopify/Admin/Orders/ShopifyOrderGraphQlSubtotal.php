<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

final class ShopifyOrderGraphQlSubtotal
{
    /**
     * Shopify order subtotal in shop currency (after discounts, before shipping and tax).
     *
     * @param  array<string, mixed>  $node
     */
    public static function subtotalShopAmount(array $node): ?string
    {
        $set = $node['currentSubtotalPriceSet'] ?? null;
        if (! is_array($set)) {
            return null;
        }

        $money = $set['shopMoney'] ?? null;
        if (! is_array($money)) {
            return null;
        }

        $amount = is_string($money['amount'] ?? null) ? trim($money['amount']) : '';
        if ($amount === '' || ! is_numeric($amount)) {
            return null;
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
