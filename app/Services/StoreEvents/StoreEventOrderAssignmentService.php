<?php

declare(strict_types=1);

namespace App\Services\StoreEvents;

use App\DAL\StoreEvents\StoreEventOrderRepository;
use App\DAL\StoreEvents\StoreEventRepository;
use App\DTOs\StoreEvents\StoreEventOrderAssignResult;
use App\Services\StoreEvents\Exceptions\StoreEventNotFoundException;

final class StoreEventOrderAssignmentService
{
    public function __construct(
        private readonly StoreEventRepository $events,
        private readonly StoreEventOrderRepository $orders,
    ) {}

    /**
     * @param  list<int>  $orderIds
     */
    public function setIncluded(string $eventUuid, array $orderIds, bool $included): StoreEventOrderAssignResult
    {
        $event = $this->events->findByUuid($eventUuid);
        if ($event === null) {
            throw new StoreEventNotFoundException('Store event not found.');
        }

        $updated = $included
            ? $this->orders->assign($orderIds, (int) $event->id)
            : $this->orders->unassignFromEvent($orderIds, (int) $event->id);

        return new StoreEventOrderAssignResult($updated);
    }

    /**
     * @param  list<int>  $orderIds
     */
    public function assignToEvent(array $orderIds, ?string $eventUuid): StoreEventOrderAssignResult
    {
        $eventId = null;
        if ($eventUuid !== null && $eventUuid !== '') {
            $event = $this->events->findByUuid($eventUuid);
            if ($event === null) {
                throw new StoreEventNotFoundException('Store event not found.');
            }
            $eventId = (int) $event->id;
        }

        return new StoreEventOrderAssignResult($this->orders->assign($orderIds, $eventId));
    }
}
