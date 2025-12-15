<?php

declare(strict_types=1);

namespace App\DAL\Maintenance;

use App\Models\MaintenanceNote;

final class EloquentMaintenanceNoteRepository implements MaintenanceNoteRepository
{
    public function findByKey(string $key): ?MaintenanceNote
    {
        return MaintenanceNote::query()->where('key', $key)->first();
    }

    public function upsert(string $key, ?string $body): MaintenanceNote
    {
        /** @var MaintenanceNote $note */
        $note = MaintenanceNote::query()->updateOrCreate(
            ['key' => $key],
            ['body' => $body],
        );

        return $note;
    }
}
