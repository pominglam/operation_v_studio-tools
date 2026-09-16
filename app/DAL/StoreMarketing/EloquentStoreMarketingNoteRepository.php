<?php

declare(strict_types=1);

namespace App\DAL\StoreMarketing;

use App\Models\StoreMarketingNote;
use Illuminate\Support\Collection;

final class EloquentStoreMarketingNoteRepository implements StoreMarketingNoteRepository
{
    public function list(?string $search): Collection
    {
        $query = StoreMarketingNote::query()->orderByDesc('happened_on')->orderBy('name');
        $needle = $search !== null ? trim($search) : '';
        if ($needle !== '') {
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('notes', 'like', '%'.$needle.'%');
            });
        }

        return $query->get();
    }

    public function findByUuid(string $uuid): ?StoreMarketingNote
    {
        return StoreMarketingNote::query()->where('uuid', $uuid)->first();
    }

    public function create(array $attributes): StoreMarketingNote
    {
        return StoreMarketingNote::query()->create($attributes);
    }

    public function update(StoreMarketingNote $note, array $attributes): StoreMarketingNote
    {
        $note->fill($attributes);
        $note->save();

        return $note->refresh();
    }

    public function delete(StoreMarketingNote $note): void
    {
        $note->delete();
    }
}
