<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

use App\Models\Shopify\ShopifyOrder;

final class ShopifyOrderAttributionSignals
{
    /**
     * @return array{tags: list<string>, payment_gateways: list<string>}
     */
    public static function fromOrder(ShopifyOrder $order): array
    {
        return [
            'tags' => self::tagsFromPayload(is_array($order->payload_json) ? $order->payload_json : null),
            'payment_gateways' => self::paymentGateways($order),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<string>
     */
    public static function tagsFromPayload(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        return self::normalizeStringList($payload['tags'] ?? null);
    }

    /**
     * @return list<string>
     */
    public static function paymentGateways(ShopifyOrder $order): array
    {
        $stored = $order->getAttribute('payment_gateway_names');
        if (is_array($stored) && $stored !== []) {
            return self::normalizeStringList($stored);
        }

        $payload = is_array($order->payload_json) ? $order->payload_json : null;
        if ($payload === null) {
            return [];
        }

        return ShopifyOrderGraphQlPaymentGateways::names($payload);
    }

    /**
     * @return list<string>
     */
    private static function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }
            $out[] = $trimmed;
        }

        return array_values(array_unique($out));
    }
}
