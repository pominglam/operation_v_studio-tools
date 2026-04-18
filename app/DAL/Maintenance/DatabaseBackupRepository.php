<?php

declare(strict_types=1);

namespace App\DAL\Maintenance;

use App\Models\DatabaseBackup;
use Illuminate\Support\Collection;

interface DatabaseBackupRepository
{
    public function create(DatabaseBackup $backup): DatabaseBackup;

    public function findByUuidOrFail(string $uuid): DatabaseBackup;

    /**
     * @return Collection<int, DatabaseBackup>
     */
    public function listRecent(int $limit = 100): Collection;
}
