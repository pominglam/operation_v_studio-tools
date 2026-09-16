<?php

declare(strict_types=1);

namespace App\Services\StoreEvents;

use App\DAL\StoreEvents\StoreEventOrderRepository;
use App\DTOs\StoreEvents\StoreEventCalendar;
use App\DTOs\StoreEvents\StoreEventDay;
use App\Models\Shopify\ShopifyOrder;
use App\Models\StoreEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class StoreEventCalendarAssembler
{
    public function __construct(
        private readonly StoreEventOrderRepository $orders,
    ) {}

    /**
     * @param  Collection<int, StoreEvent>  $events
     * @return Collection<int, StoreEventCalendar>
     */
    public function assemble(Collection $events): Collection
    {
        if ($events->isEmpty()) {
            return collect();
        }

        $byDay = $this->ordersByDay($this->range($events));

        return $events->map(fn (StoreEvent $event): StoreEventCalendar => $this->forEvent($event, $byDay));
    }

    /**
     * @param  Collection<int, StoreEvent>  $events
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(Collection $events): array
    {
        $timezone = $this->timezone();
        $starts = $events->min(fn (StoreEvent $event): string => $event->starts_on->toDateString());
        $ends = $events->max(fn (StoreEvent $event): string => $event->ends_on->toDateString());
        $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $starts, $timezone);
        $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $ends, $timezone);
        if ($start === false || $end === false) {
            throw new \InvalidArgumentException('Invalid store event date range.');
        }

        return [$start->startOfDay(), $end->addDay()->startOfDay()];
    }

    /**
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return Collection<string, Collection<int, ShopifyOrder>>
     */
    private function ordersByDay(array $range): Collection
    {
        $timezone = $this->timezone();

        return $this->orders->eligibleBetween($range[0], $range[1])
            ->groupBy(function (ShopifyOrder $order) use ($timezone): string {
                $orderedAt = $order->ordered_at_shop_tz;
                if ($orderedAt === null) {
                    return '';
                }

                return CarbonImmutable::parse($orderedAt)->timezone($timezone)->format('Y-m-d');
            });
    }

    /**
     * @param  Collection<string, Collection<int, ShopifyOrder>>  $byDay
     */
    private function forEvent(StoreEvent $event, Collection $byDay): StoreEventCalendar
    {
        $days = [];
        $eventCount = 0;
        $eventSubtotal = '0.00';
        foreach ($this->dates($event) as $date) {
            $day = $this->day($event, $date, $byDay->get($date, collect()));
            $days[] = $day;
            $eventCount += $day->eventOrderCount;
            $eventSubtotal = $this->addMoney($eventSubtotal, $day->eventSubtotal);
        }

        return new StoreEventCalendar($event, $eventCount, $eventSubtotal, $days);
    }

    /**
     * @return list<string>
     */
    private function dates(StoreEvent $event): array
    {
        $dates = [];
        $cursor = CarbonImmutable::parse($event->starts_on->toDateString());
        $end = CarbonImmutable::parse($event->ends_on->toDateString());
        for (; $cursor->lte($end); $cursor = $cursor->addDay()) {
            $dates[] = $cursor->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @param  Collection<int, ShopifyOrder>  $orders
     */
    private function day(StoreEvent $event, string $date, Collection $orders): StoreEventDay
    {
        $eventCount = 0;
        $otherCount = 0;
        $eventSubtotal = '0.00';
        $otherSubtotal = '0.00';
        foreach ($orders as $order) {
            $amount = $this->subtotal($order);
            if ((int) $order->store_event_id === (int) $event->id) {
                $eventCount++;
                $eventSubtotal = $this->addMoney($eventSubtotal, $amount);
            } else {
                $otherCount++;
                $otherSubtotal = $this->addMoney($otherSubtotal, $amount);
            }
        }

        return new StoreEventDay(
            $date,
            $eventCount,
            $otherCount,
            $eventSubtotal,
            $otherSubtotal,
            $orders->values()->all(),
        );
    }

    private function subtotal(ShopifyOrder $order): string
    {
        $amount = $order->subtotal_shop_amount;
        if ($amount === null || ! is_numeric((string) $amount)) {
            return '0.00';
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function addMoney(string $left, string $right): string
    {
        return number_format((float) $left + (float) $right, 2, '.', '');
    }

    private function timezone(): string
    {
        return (string) config('shopify.staff_order_report.timezone', 'America/Toronto');
    }
}
