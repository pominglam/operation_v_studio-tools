<?php

declare(strict_types=1);

namespace App\Support\Shopify\Admin\Orders;

final class ShopifyOrderGraphQlCustomerIdentity
{
    /**
     * @param  array<string, mixed>  $node
     * @return array{customer_gid: ?string, customer_email: ?string, customer_phone: ?string}
     */
    public static function attributesFromGraphQlNode(array $node): array
    {
        $customer = is_array($node['customer'] ?? null) ? $node['customer'] : [];

        return [
            'customer_gid' => self::customerGid($customer),
            'customer_email' => self::normalizeEmail(self::firstNonEmpty(
                self::nestedString($customer, 'defaultEmailAddress', 'emailAddress'),
                self::scalarString($node['email'] ?? null),
            )),
            'customer_phone' => self::firstNonEmpty(
                self::nestedString($customer, 'defaultPhoneNumber', 'phoneNumber'),
                self::scalarString($node['phone'] ?? null),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private static function customerGid(array $customer): ?string
    {
        $gid = self::scalarString($customer['id'] ?? null);
        if ($gid === null || ! str_starts_with($gid, 'gid://shopify/Customer/')) {
            return null;
        }

        return $gid;
    }

    private static function nestedString(array $parent, string $objectKey, string $valueKey): ?string
    {
        $object = $parent[$objectKey] ?? null;
        if (! is_array($object)) {
            return null;
        }

        return self::scalarString($object[$valueKey] ?? null);
    }

    private static function scalarString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function normalizeEmail(?string $email): ?string
    {
        return $email !== null ? strtolower($email) : null;
    }

    private static function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }
}
