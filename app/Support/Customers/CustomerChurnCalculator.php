<?php

declare(strict_types=1);

namespace App\Support\Customers;

use Carbon\CarbonImmutable;

final class CustomerChurnCalculator
{
    /**
     * @param  list<CustomerRetentionOrderRef>  $orders
     */
    public static function fromOrders(array $orders, CarbonImmutable $now): ?CustomerChurnAssessment
    {
        $times = [];
        foreach ($orders as $order) {
            if ($order->orderedAtIso === null || trim($order->orderedAtIso) === '') {
                continue;
            }
            $times[] = CarbonImmutable::parse($order->orderedAtIso)->getTimestamp();
        }
        sort($times);
        if (count($times) < 2) {
            return null;
        }

        $avgDays = self::averageGapDays($times);
        $daysSinceLast = intdiv(max(0, $now->getTimestamp() - $times[array_key_last($times)]), 86400);
        $thresholdDays = 2 * $avgDays;

        return new CustomerChurnAssessment(
            status: $daysSinceLast > $thresholdDays ? CustomerChurnStatus::CHURNED : CustomerChurnStatus::ACTIVE,
            avgGapDays: $avgDays,
            daysSinceLast: $daysSinceLast,
            thresholdDays: $thresholdDays,
        );
    }

    /**
     * @param  list<int>  $times
     */
    private static function averageGapDays(array $times): int
    {
        $gapSeconds = 0;
        $gaps = 0;
        for ($i = 1, $count = count($times); $i < $count; $i++) {
            $gapSeconds += max(0, $times[$i] - $times[$i - 1]);
            $gaps++;
        }

        return max(1, (int) round($gapSeconds / $gaps / 86400));
    }
}
