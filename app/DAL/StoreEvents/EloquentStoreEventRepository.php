<?php

declare(strict_types=1);

namespace App\DAL\StoreEvents;

use App\Models\StoreEvent;
use Illuminate\Support\Collection;

final class EloquentStoreEventRepository implements StoreEventRepository
{
    public function list(?string $search): Collection
    {
        $query = StoreEvent::query()->orderByDesc('starts_on')->orderBy('name');
        $needle = $search !== null ? trim($search) : '';
        if ($needle !== '') {
            $query->where('name', 'like', '%'.$needle.'%');
        }

        return $query->get();
    }

    public function findByUuid(string $uuid): ?StoreEvent
    {
        return StoreEvent::query()->where('uuid', $uuid)->first();
    }

    public function create(array $attributes): StoreEvent
    {
        return StoreEvent::query()->create($attributes);
    }

    public function update(StoreEvent $event, array $attributes): StoreEvent
    {
        $event->fill($attributes);
        $event->save();

        return $event->refresh();
    }

    public function delete(StoreEvent $event): void
    {
        $event->delete();
    }
}
