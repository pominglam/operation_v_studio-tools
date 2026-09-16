<?php

declare(strict_types=1);

namespace App\Support\Customers;

final class ShopifyRfmGroup
{
    public const CHAMPIONS = 'CHAMPIONS';

    public const LOYAL = 'LOYAL';

    public const ACTIVE = 'ACTIVE';

    public const NEW = 'NEW';

    public const PROMISING = 'PROMISING';

    public const NEEDS_ATTENTION = 'NEEDS_ATTENTION';

    public const AT_RISK = 'AT_RISK';

    public const PREVIOUSLY_LOYAL = 'PREVIOUSLY_LOYAL';

    public const ALMOST_LOST = 'ALMOST_LOST';

    public const DORMANT = 'DORMANT';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::CHAMPIONS,
            self::LOYAL,
            self::ACTIVE,
            self::NEW,
            self::PROMISING,
            self::NEEDS_ATTENTION,
            self::AT_RISK,
            self::PREVIOUSLY_LOYAL,
            self::ALMOST_LOST,
            self::DORMANT,
        ];
    }

    public static function fromScores(int $recency, int $frequency, int $monetary): string
    {
        $fm = intdiv($frequency + $monetary, 2);

        return match (true) {
            $recency === 5 && $fm > 3 => self::CHAMPIONS,
            $recency >= 3 && $recency <= 4 && $fm > 3 => self::LOYAL,
            $recency >= 4 && $fm >= 2 && $fm <= 3 => self::ACTIVE,
            $recency === 5 && $fm <= 1 => self::NEW,
            $recency === 4 && $fm <= 1 => self::PROMISING,
            $recency === 3 && $fm === 3 => self::NEEDS_ATTENTION,
            $recency <= 2 && $fm >= 3 && $fm <= 4 => self::AT_RISK,
            $recency <= 2 && $fm > 4 => self::PREVIOUSLY_LOYAL,
            $recency === 3 && $fm <= 2 => self::ALMOST_LOST,
            default => self::DORMANT,
        };
    }
}
