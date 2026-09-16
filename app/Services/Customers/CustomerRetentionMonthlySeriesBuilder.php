<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Support\Customers\CustomerRetentionPerson;
use App\Support\Customers\CustomerRetentionTimezone;
use App\Support\Customers\ShopMoney;
use Carbon\CarbonImmutable;

final class CustomerRetentionMonthlySeriesBuilder
{
    /**
     * @param  list<CustomerRetentionPerson>  $people
     * @return list<array<string, mixed>>
     */
    public function build(array $people): array
    {
        $timezone = CustomerRetentionTimezone::NAME;
        $buckets = [];
        foreach ($people as $person) {
            $this->addPerson($buckets, $person, $timezone);
        }

        return $this->finalize($buckets);
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function addPerson(array &$buckets, CustomerRetentionPerson $person, string $timezone): void
    {
        $monthSpend = [];
        foreach ($person->orders as $order) {
            $month = $this->monthKey($order->orderedAtIso, $timezone);
            if ($month === null) {
                continue;
            }
            $amount = $order->subtotal ?? '0.00';
            $this->ensure($buckets, $month);
            $buckets[$month]['orders']++;
            $buckets[$month]['spend'] = ShopMoney::add($buckets[$month]['spend'], $amount);
            $monthSpend[$month] = ShopMoney::add($monthSpend[$month] ?? '0.00', $amount);
        }
        if ($monthSpend === []) {
            return;
        }
        ksort($monthSpend);
        $firstMonth = array_key_first($monthSpend);
        $this->ensure($buckets, $firstMonth);
        $buckets[$firstMonth]['acquired']++;
        $buckets[$firstMonth]['acquired_spend'] = ShopMoney::add(
            $buckets[$firstMonth]['acquired_spend'],
            $monthSpend[$firstMonth],
        );
        if ($person->isRepeat) {
            $buckets[$firstMonth]['acquired_now_repeat']++;
        }
        foreach ($monthSpend as $month => $amount) {
            if ($month === $firstMonth) {
                continue;
            }
            $buckets[$month]['returning_buyers']++;
            $buckets[$month]['returning_spend'] = ShopMoney::add($buckets[$month]['returning_spend'], $amount);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     * @return list<array<string, mixed>>
     */
    private function finalize(array $buckets): array
    {
        if ($buckets === []) {
            return [];
        }
        ksort($buckets);
        $cursor = CarbonImmutable::createFromFormat('Y-m', array_key_first($buckets))?->startOfMonth();
        $end = CarbonImmutable::createFromFormat('Y-m', array_key_last($buckets))?->startOfMonth();
        $rows = [];
        while ($cursor !== null && $end !== null && $cursor->lte($end)) {
            $key = $cursor->format('Y-m');
            $this->ensure($buckets, $key);
            $row = $buckets[$key];
            $buyers = $row['acquired'] + $row['returning_buyers'];
            $orders = $row['orders'];
            $rows[] = [
                'month' => $key,
                'acquired' => $row['acquired'],
                'returning_buyers' => $row['returning_buyers'],
                'buyers' => $buyers,
                'buyer_return_rate' => $buyers > 0
                    ? number_format($row['returning_buyers'] / $buyers, 4, '.', '')
                    : '0.0000',
                'orders' => $orders,
                'spend' => $row['spend'],
                'acquired_spend' => $row['acquired_spend'],
                'returning_spend' => $row['returning_spend'],
                'aov' => ShopMoney::divide($row['spend'], $orders),
                'acquired_now_repeat' => $row['acquired_now_repeat'],
            ];
            $cursor = $cursor->addMonth();
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, mixed>>  $buckets
     */
    private function ensure(array &$buckets, string $month): void
    {
        $buckets[$month] ??= [
            'acquired' => 0,
            'returning_buyers' => 0,
            'orders' => 0,
            'spend' => '0.00',
            'acquired_spend' => '0.00',
            'returning_spend' => '0.00',
            'acquired_now_repeat' => 0,
        ];
    }

    private function monthKey(?string $iso, string $timezone): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }

        return CarbonImmutable::parse($iso)->timezone($timezone)->format('Y-m');
    }
}
