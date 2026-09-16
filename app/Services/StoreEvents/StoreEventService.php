<?php

declare(strict_types=1);

namespace App\Services\StoreEvents;

use App\DAL\StoreEvents\StoreEventRepository;
use App\Models\StoreEvent;
use App\Services\StoreEvents\Exceptions\StoreEventNotFoundException;
use App\Support\StoreEvents\StoreEventWriteData;
use Illuminate\Support\Collection;

final class StoreEventService
{
    public function __construct(
        private readonly StoreEventRepository $events,
    ) {}

    /**
     * @return Collection<int, StoreEvent>
     */
    public function list(?string $search): Collection
    {
        return $this->events->list($search);
    }

    public function create(StoreEventWriteData $data): StoreEvent
    {
        return $this->events->create($this->attributes($data, null));
    }

    public function update(string $uuid, StoreEventWriteData $data): StoreEvent
    {
        $event = $this->require($uuid);

        return $this->events->update($event, $this->attributes($data, $event));
    }

    public function delete(string $uuid): void
    {
        $this->events->delete($this->require($uuid));
    }

    public function require(string $uuid): StoreEvent
    {
        $event = $this->events->findByUuid($uuid);
        if ($event === null) {
            throw new StoreEventNotFoundException('Store event not found.');
        }

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StoreEventWriteData $data, ?StoreEvent $existing): array
    {
        $cancelledAt = null;
        if ($data->cancelled) {
            $cancelledAt = $existing?->cancelled_at ?? now();
        }

        return [
            'name' => $data->name,
            'starts_on' => $data->startsOn,
            'ends_on' => $data->endsOn,
            'notes' => $data->notes,
            'cancelled_at' => $cancelledAt,
        ];
    }
}
