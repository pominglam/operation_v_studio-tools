<?php

declare(strict_types=1);

namespace App\Support\Plamod;

final class PlamodPreorderInterestFilter
{
    public const string INTERESTED = 'interested';

    public const string NOT_INTERESTED = 'not_interested';

    public const string ALL = 'all';

    /** @var list<string> */
    public const array ALL_VALUES = [self::INTERESTED, self::NOT_INTERESTED, self::ALL];

    public static function normalize(?string $value): string
    {
        $trimmed = strtolower(trim((string) $value));

        return in_array($trimmed, self::ALL_VALUES, true) ? $trimmed : self::INTERESTED;
    }
}
