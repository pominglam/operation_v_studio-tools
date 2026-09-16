<?php

declare(strict_types=1);

namespace App\DTOs\StoreEvents;

use App\Models\StoreEvent;

final readonly class StoreEventCalendar
{
    /**
     * @param  list<StoreEventDay>  $days
     */
    public function __construct(
        public StoreEvent $event,
        public int $eventOrderCount,
        public string $eventSubtotal,
        public array $days,
    ) {}
}
