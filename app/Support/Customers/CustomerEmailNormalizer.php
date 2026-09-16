<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerEmailNormalizer
{
    /**
     * @param  list<string>  $denylist
     */
    public static function normalize(?string $email, array $denylist = []): ?string
    {
        $trimmed = strtolower(trim((string) $email));
        if ($trimmed === '' || ! str_contains($trimmed, '@')) {
            return null;
        }

        return in_array($trimmed, $denylist, true) ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    public static function denylistFromConfig(): array
    {
        $raw = config('shopify.customer_retention.email_denylist', []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $normalized = strtolower(trim($item));
            if ($normalized !== '') {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }
}
