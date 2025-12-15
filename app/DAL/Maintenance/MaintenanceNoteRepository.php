<?php

declare(strict_types=1);

namespace App\DAL\Maintenance;

use App\Models\MaintenanceNote;

interface MaintenanceNoteRepository
{
    public function findByKey(string $key): ?MaintenanceNote;

    public function upsert(string $key, ?string $body): MaintenanceNote;
}
