<?php

declare(strict_types=1);

namespace App\Support\CustomOrders;

final class CustomAsiaOrderContactMedia
{
    public const IG = 'ig';

    public const FB = 'fb';

    /** @var list<string> */
    public const ALL = [
        self::IG,
        self::FB,
    ];

    public static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));

        return in_array($value, self::ALL, true) ? $value : null;
    }

    public static function label(string $value): string
    {
        return match (self::normalize($value)) {
            self::IG => 'Instagram',
            self::FB => 'Facebook',
            default => $value,
        };
    }
}
