<?php

declare(strict_types=1);

namespace App\Support\Plamod;

final class PlamodPreorderIndexSort
{
    public const string NAME = 'name';

    public const string RELEASE = 'release';

    public const string CATEGORY = 'category';

    public const string STOCK = 'stock';

    public const string SELL = 'sell';

    public const string QTY = 'qty';

    public const string CLOSING = 'closing';

    public const string ETA = 'eta';

    public const string ETA_MONTHS = 'eta_months';

    /** @var list<string> */
    public const array ALL = [
        self::NAME,
        self::RELEASE,
        self::CATEGORY,
        self::STOCK,
        self::SELL,
        self::QTY,
        self::CLOSING,
        self::ETA,
        self::ETA_MONTHS,
    ];

    public static function normalize(?string $value): string
    {
        $trimmed = strtolower(trim((string) $value));

        return in_array($trimmed, self::ALL, true) ? $trimmed : self::CLOSING;
    }

    public static function normalizeDir(?string $value): string
    {
        return strtolower(trim((string) $value)) === 'desc' ? 'desc' : 'asc';
    }
}
