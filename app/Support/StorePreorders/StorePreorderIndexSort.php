<?php

declare(strict_types=1);

namespace App\Support\StorePreorders;

final class StorePreorderIndexSort
{
    public const string DEFAULT = 'closing';

    /** @var list<string> */
    public const array ALL = ['closing', 'opened', 'name'];

    public static function normalize(?string $value): string
    {
        $trimmed = strtolower(trim((string) $value));

        return in_array($trimmed, self::ALL, true) ? $trimmed : self::DEFAULT;
    }

    public static function normalizeDir(?string $value): string
    {
        return strtolower(trim((string) $value)) === 'desc' ? 'desc' : 'asc';
    }
}
