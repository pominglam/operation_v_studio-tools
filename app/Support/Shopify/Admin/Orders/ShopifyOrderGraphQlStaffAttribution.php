<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

final class ShopifyOrderGraphQlStaffAttribution
{
    /**
     * @param  array<string, mixed>  $node
     */
    public static function sourceName(array $node): ?string
    {
        $source = is_string($node['sourceName'] ?? null) ? trim($node['sourceName']) : '';

        return $source !== '' ? $source : null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public static function channelName(array $node): ?string
    {
        $channel = is_array($node['channelInformation']['channelDefinition'] ?? null)
            ? ($node['channelInformation']['channelDefinition']['channelName'] ?? null)
            : null;
        if (! is_string($channel)) {
            return null;
        }

        $channel = trim($channel);

        return $channel !== '' ? $channel : null;
    }
}
