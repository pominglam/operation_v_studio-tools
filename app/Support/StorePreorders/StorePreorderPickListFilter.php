<?php

declare(strict_types=1);

namespace App\Support\StorePreorders;

final class StorePreorderPickListFilter
{
    public const string ALL = 'all';

    public const string NOT_OPENED = 'not_opened';

    public const string OPENED = 'opened';

    /** @var list<string> */
    public const array ALL_VALUES = [self::ALL, self::NOT_OPENED, self::OPENED];

    public static function normalize(?string $value): string
    {
        $trimmed = strtolower(trim((string) $value));

        return in_array($trimmed, self::ALL_VALUES, true) ? $trimmed : self::ALL;
    }
}
