<?php

declare(strict_types=1);

namespace App\Support\SpecialOrders;

use Illuminate\Support\Carbon;

final class SpecialOrderEta
{
    public static function computeDate(?Carbon $merchandiserOrderedAt, ?int $receiveDelayDays): ?string
    {
        if ($merchandiserOrderedAt === null || $receiveDelayDays === null || $receiveDelayDays < 1) {
            return null;
        }

        return $merchandiserOrderedAt
            ->copy()
            ->timezone('America/Toronto')
            ->startOfDay()
            ->addDays($receiveDelayDays)
            ->toDateString();
    }
}
