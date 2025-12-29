<?php

declare(strict_types=1);

namespace App\DAL\Maintenance;

use App\Models\DatabaseBackup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class EloquentDatabaseBackupRepository implements DatabaseBackupRepository
{
    public function create(DatabaseBackup $backup): DatabaseBackup
    {
        $backup->save();

        return $backup;
    }

    public function findByUuidOrFail(string $uuid): DatabaseBackup
    {
        $backup = DatabaseBackup::query()->where('uuid', '=', $uuid)->first();
        if (! $backup) {
            throw new ModelNotFoundException("DatabaseBackup not found for uuid {$uuid}");
        }

        return $backup;
    }

    public function listRecent(int $limit = 100): Collection
    {
        $limit = max(1, min($limit, 500));

        return DatabaseBackup::query()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}


