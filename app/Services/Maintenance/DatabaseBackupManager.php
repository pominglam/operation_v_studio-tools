<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Models\DatabaseBackup;

interface DatabaseBackupManager
{
    /**
     * @return array<int, DatabaseBackup>
     */
    public function listRecent(int $limit = 100): array;

    public function create(string $description, string $createdBy = 'manual'): DatabaseBackup;
}
