<?php

declare(strict_types=1);

namespace App\DAL\StoreMarketing;

use App\Models\StoreMarketingNote;
use Illuminate\Support\Collection;

interface StoreMarketingNoteRepository
{
    /**
     * @return Collection<int, StoreMarketingNote>
     */
    public function list(?string $search): Collection;

    public function findByUuid(string $uuid): ?StoreMarketingNote;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StoreMarketingNote;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StoreMarketingNote $note, array $attributes): StoreMarketingNote;

    public function delete(StoreMarketingNote $note): void;
}
