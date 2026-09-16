<?php

declare(strict_types=1);

namespace App\Support\Plamod;

use DateTimeInterface;

final class PlamodPreorderEtaLeadMonths
{
    public static function between(mixed $release, mixed $eta): ?int
    {
        $releaseParts = self::yearMonth($release);
        $etaParts = self::yearMonth($eta);
        if ($releaseParts === null || $etaParts === null) {
            return null;
        }

        return ($etaParts[0] * 12 + $etaParts[1]) - ($releaseParts[0] * 12 + $releaseParts[1]);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function yearMonth(mixed $value): ?array
    {
        if ($value instanceof DateTimeInterface) {
            return [(int) $value->format('Y'), (int) $value->format('n')];
        }

        $text = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2})/', $text, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }
}
