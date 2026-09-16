<?php

declare(strict_types=1);

namespace App\DAL\StoreEvents;

use App\Models\StoreEvent;
use Illuminate\Support\Collection;

interface StoreEventRepository
{
    /**
     * @return Collection<int, StoreEvent>
     */
    public function list(?string $search): Collection;

    public function findByUuid(string $uuid): ?StoreEvent;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StoreEvent;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoreEvent $event, array $attributes): StoreEvent;

    public function delete(StoreEvent $event): void;
}
