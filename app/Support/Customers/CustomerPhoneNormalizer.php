<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerPhoneNormalizer
{
    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 10 ? $digits : null;
    }
}
