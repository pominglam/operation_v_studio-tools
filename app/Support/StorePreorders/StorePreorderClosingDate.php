<?php

declare(strict_types=1);

namespace App\Support\StorePreorders;

use Illuminate\Support\Carbon;

/**
 * Store window ends one calendar day before Plamod's due date
 * so staff have 24h to place the distributor preorder.
 */
final class StorePreorderClosingDate
{
    public static function defaultFromPlamodDue(mixed $poDueDate): ?Carbon
    {
        $due = self::parseDay($poDueDate);
        if ($due === null) {
            return null;
        }

        return $due->subDay();
    }

    public static function parseDay(mixed $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy()->timezone('America/Toronto')->startOfDay();
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return Carbon::parse($trimmed, 'America/Toronto')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
