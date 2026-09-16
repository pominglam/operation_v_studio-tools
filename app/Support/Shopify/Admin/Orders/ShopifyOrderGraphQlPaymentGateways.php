<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

final class ShopifyOrderGraphQlPaymentGateways
{
    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public static function names(array $node): array
    {
        $raw = $node['paymentGatewayNames'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $name) {
            if (! is_string($name) && ! is_numeric($name)) {
                continue;
            }
            $trimmed = trim((string) $name);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
        }

        return array_values(array_unique($out));
    }
}
