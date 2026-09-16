<?php

declare(strict_types=1);

namespace App\Support\Customers;

use Carbon\CarbonImmutable;

final class CustomerStoreCadenceCalculator
{
    public const GAP_FLOOR_DAYS = 14;

    public const LAPSED_FLOOR_DAYS = 60;

    public const FALLBACK_MEDIAN_DAYS = 30;

    /**
     * @param  list<CustomerRetentionPerson>  $people
     */
    public static function fromPeople(array $people): CustomerStoreCadence
    {
        $sets = [];
        foreach ($people as $person) {
            $sets[] = $person->orders;
        }

        return self::fromOrderSets($sets);
    }

    /**
     * @param  list<list<CustomerRetentionOrderRef>>  $orderSets
     */
    public static function fromOrderSets(array $orderSets): CustomerStoreCadence
    {
        $gaps = [];
        foreach ($orderSets as $orders) {
            foreach (self::typicalGaps($orders) as $gap) {
                $gaps[] = $gap;
            }
        }
        $median = self::medianDays($gaps);

        return new CustomerStoreCadence(
            medianGapDays: $median,
            dueAfterDays: $median,
            lapsedAfterDays: max(self::LAPSED_FLOOR_DAYS, 2 * $median),
            typicalGapCount: count($gaps),
            gapFloorDays: self::GAP_FLOOR_DAYS,
        );
    }

    /**
     * @param  list<CustomerRetentionOrderRef>  $orders
     */
    public static function assess(
        array $orders,
        CarbonImmutable $now,
        CustomerStoreCadence $rhythm,
    ): ?CustomerCadenceAssessment {
        $days = self::daysSinceLast($orders, $now);
        if ($days === null) {
            return null;
        }

        return new CustomerCadenceAssessment(
            status: self::statusFor($days, $rhythm),
            daysSinceLast: $days,
            dueAfterDays: $rhythm->dueAfterDays,
            lapsedAfterDays: $rhythm->lapsedAfterDays,
        );
    }

    public static function statusFor(int $daysSinceLast, CustomerStoreCadence $rhythm): string
    {
        if ($daysSinceLast > $rhythm->lapsedAfterDays) {
            return CustomerCadenceStatus::LAPSED;
        }
        if ($daysSinceLast >= $rhythm->dueAfterDays) {
            return CustomerCadenceStatus::DUE;
        }

        return CustomerCadenceStatus::ON_CADENCE;
    }

    /**
     * @param  list<CustomerRetentionOrderRef>  $orders
     * @return list<int>
     */
    public static function typicalGaps(array $orders): array
    {
        $times = self::sortedTimestamps($orders);
        $gaps = [];
        for ($i = 1, $count = count($times); $i < $count; $i++) {
            $days = intdiv(max(0, $times[$i] - $times[$i - 1]), 86400);
            if ($days >= self::GAP_FLOOR_DAYS) {
                $gaps[] = $days;
            }
        }

        return $gaps;
    }

    /**
     * @param  list<CustomerRetentionOrderRef>  $orders
     */
    public static function daysSinceLast(array $orders, CarbonImmutable $now): ?int
    {
        $times = self::sortedTimestamps($orders);
        if ($times === []) {
            return null;
        }

        return intdiv(max(0, $now->getTimestamp() - $times[array_key_last($times)]), 86400);
    }

    /**
     * @param  list<int>  $gaps
     */
    private static function medianDays(array $gaps): int
    {
        if ($gaps === []) {
            return self::FALLBACK_MEDIAN_DAYS;
        }
        sort($gaps);
        $count = count($gaps);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $gaps[$mid];
        }

        return (int) round(($gaps[$mid - 1] + $gaps[$mid]) / 2);
    }

    /**
     * @param  list<CustomerRetentionOrderRef>  $orders
     * @return list<int>
     */
    private static function sortedTimestamps(array $orders): array
    {
        $times = [];
        foreach ($orders as $order) {
            if ($order->orderedAtIso === null || trim($order->orderedAtIso) === '') {
                continue;
            }
            $times[] = CarbonImmutable::parse($order->orderedAtIso)->getTimestamp();
        }
        sort($times);

        return $times;
    }
}
