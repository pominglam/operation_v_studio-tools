<?php

declare(strict_types=1);

namespace App\Support\StorePreorders;

final class StorePreorderStatus
{
    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    /** @var list<string> */
    public const array ALL = [self::OPEN, self::CLOSED];

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtolower(trim($value));
        if ($trimmed === '' || $trimmed === 'all') {
            return null;
        }

        return in_array($trimmed, self::ALL, true) ? $trimmed : null;
    }
}
