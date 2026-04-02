<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\TcgEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<TcgEvent> */
final class TcgEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TcgEvent $event */
        $event = $this->resource;

        $mapsUrl = null;
        if ($event->lat !== null && $event->lng !== null) {
            $mapsUrl = 'https://www.google.com/maps/search/?api=1&query='
                .rawurlencode((string) $event->lat.','.(string) $event->lng);
        }

        $eventUrl = 'https://www.bandai-tcg-plus.com/event/'.(string) $event->external_event_id;

        return [
            'id' => $event->uuid,
            'source' => $event->source,
            'external_event_id' => $event->external_event_id,
            'store_name' => $event->store_name,
            'store_url' => $event->store_url,
            'store_sns_url' => $event->store_sns_url,
            'phone_number' => $event->phone_number,
            'location' => [
                'street_address' => $event->street_address,
                'city' => $event->city,
                'pref_code' => $event->pref_code,
                'postcode' => $event->postcode,
                'lat' => $event->lat,
                'lng' => $event->lng,
                'maps_url' => $mapsUrl,
            ],
            'event_name' => $event->event_name,
            'event_url' => $eventUrl,
            'start_datetime' => optional($event->start_datetime)->toISOString(),
            'timezone' => $event->timezone,
            'format' => $event->format,
            'excerpt' => $event->excerpt,
            'lottery_method' => $event->lottery_method,
            'entry_fee' => $event->entry_fee,
            'entry_fee_currency_code' => $event->entry_fee_currency_code,
            'capacity' => $event->capacity,
            'applicants' => $event->applicants,
            'status' => $event->status,
            'fetched_at' => optional($event->fetched_at)->toISOString(),
        ];
    }
}

