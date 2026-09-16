<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class CustomerFrequencyLabel
{
    public const NEW = 'new';

    public const REPEAT = 'repeat';

    public const LOYAL = 'loyal';

    /**
     * Mutually exclusive column label: 1 = new, 2 = repeat, 3+ = loyal.
     */
    public static function fromOrderCount(int $orderCount): string
    {
        if ($orderCount >= 3) {
            return self::LOYAL;
        }

        return $orderCount >= 2 ? self::REPEAT : self::NEW;
    }

    public static function isRepeat(int $orderCount): bool
    {
        return $orderCount >= 2;
    }

    /**
     * @return list<string>
     */
    public static function allowedFilters(): array
    {
        return [self::NEW, self::REPEAT, self::LOYAL];
    }
}
