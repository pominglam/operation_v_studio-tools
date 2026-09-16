<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\StoreEvents\StoreEventCalendar;
use App\DTOs\StoreEvents\StoreEventDay;
use App\Models\Shopify\ShopifyOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StoreEventCalendar */
final class StoreEventCalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StoreEventCalendar $calendar */
        $calendar = $this->resource;
        $event = $calendar->event;

        return [
            'id' => $event->uuid,
            'name' => $event->name,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'notes' => $event->notes,
            'cancelled' => $event->cancelled_at !== null,
            'cancelled_at' => $event->cancelled_at?->toIso8601String(),
            'event_order_count' => $calendar->eventOrderCount,
            'event_subtotal' => $calendar->eventSubtotal,
            'days' => array_map(
                fn (StoreEventDay $day): array => $this->day($request, $event->uuid, $day),
                $calendar->days,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function day(Request $request, string $eventUuid, StoreEventDay $day): array
    {
        return [
            'date' => $day->date,
            'event_order_count' => $day->eventOrderCount,
            'other_order_count' => $day->otherOrderCount,
            'order_count' => $day->eventOrderCount + $day->otherOrderCount,
            'event_subtotal' => $day->eventSubtotal,
            'other_subtotal' => $day->otherSubtotal,
            'orders' => array_map(
                function (ShopifyOrder $order) use ($request, $eventUuid): array {
                    $row = (new ShopifyOrderResource($order))->toArray($request);
                    $row['in_event'] = ($order->storeEvent?->uuid ?? null) === $eventUuid;

                    return $row;
                },
                $day->orders,
            ),
        ];
    }
}
