<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class ShopifyRfmCatalog
{
    /**
     * @return list<array{key: string, name: string, rule: string, who: string}>
     */
    public static function groups(): array
    {
        return [
            self::row(ShopifyRfmGroup::CHAMPIONS, 'Champions', 'R = 5 and FM > 3', 'Super loyal — very recent, many orders, highest spend.'),
            self::row(ShopifyRfmGroup::LOYAL, 'Loyal', 'R is 3 or 4, and FM > 3', 'Recent, many orders, highest spend — one recency step below Champions.'),
            self::row(ShopifyRfmGroup::ACTIVE, 'Active', 'R ≥ 4 and FM is 2 or 3', 'Recent, some orders, moderate spend.'),
            self::row(ShopifyRfmGroup::NEW, 'New', 'R = 5 and FM ≤ 1', 'Shopify New (not our 1-order New): very recent, low spend so far.'),
            self::row(ShopifyRfmGroup::PROMISING, 'Promising', 'R = 4 and FM ≤ 1', 'Recent, fewer orders, low spend.'),
            self::row(ShopifyRfmGroup::NEEDS_ATTENTION, 'Needs attention', 'R = 3 and FM = 3', 'Mid recency, mid frequency and spend — cooling.'),
            self::row(ShopifyRfmGroup::AT_RISK, 'At risk', 'R ≤ 2 and FM is 3 or 4', 'Not recent, but a strong history of orders and spend.'),
            self::row(ShopifyRfmGroup::PREVIOUSLY_LOYAL, 'Previously loyal', 'R ≤ 2 and FM > 4', 'Not recent, very strong history — lapsed best customers.'),
            self::row(ShopifyRfmGroup::ALMOST_LOST, 'Almost lost', 'R = 3 and FM ≤ 2', 'Mid recency, thin history, lower spend.'),
            self::row(ShopifyRfmGroup::DORMANT, 'Dormant', 'R ≤ 2 and FM ≤ 2', 'Not recent, infrequent orders, low spend.'),
        ];
    }

    public static function nameFor(string $key): string
    {
        foreach (self::groups() as $group) {
            if ($group['key'] === $key) {
                return $group['name'];
            }
        }

        return $key;
    }

    /**
     * @return array{key: string, name: string, rule: string, who: string}
     */
    private static function row(string $key, string $name, string $rule, string $who): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'rule' => $rule,
            'who' => $who,
        ];
    }
}
