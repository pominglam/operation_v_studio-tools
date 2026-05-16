<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Auth;

/**
 * Verifies OAuth callback/install query strings per Shopify's HMAC guidance (client_secret as key).
 */
final class ShopifyOAuthQueryHmacVerifier
{
    /**
     * @param  array<string, scalar|array|string|null>  $params
     * @return array<string, string>
     */
    public static function normalizeParamsForSigning(array $params): array
    {
        unset($params['hmac'], $params['signature']);

        /** @var array<string, string> $flat */
        $flat = [];
        foreach ($params as $key => $value) {
            $keyString = (string) $key;
            if ($value === null || is_object($value)) {
                continue;
            }
            if (is_array($value)) {
                $flat[$keyString] = implode(',', array_map(static fn ($item): string => (string) $item, $value));

                continue;
            }

            $flat[$keyString] = (string) $value;
        }

        return $flat;
    }

    /**
     * @param  array<string, string>  $filteredParamsWithoutHmac
     */
    public static function buildMessageSorted(array $filteredParamsWithoutHmac): string
    {
        ksort($filteredParamsWithoutHmac, SORT_STRING);
        $pairs = [];
        foreach ($filteredParamsWithoutHmac as $k => $v) {
            $pairs[] = $k.'='.$v;
        }

        return implode('&', $pairs);
    }

    public static function verify(string $message, string $hmacHexProvided, string $clientSecret): bool
    {
        $clientSecret = trim($clientSecret);
        $hmacHexProvided = trim($hmacHexProvided);
        if ($clientSecret === '' || $hmacHexProvided === '') {
            return false;
        }

        $calc = hash_hmac('sha256', $message, $clientSecret, false);

        return hash_equals(strtolower($calc), strtolower($hmacHexProvided));
    }
}
