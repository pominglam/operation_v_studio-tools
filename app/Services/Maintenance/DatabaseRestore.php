<?php

declare(strict_types=1);

namespace App\Services\Maintenance;

use App\Models\DatabaseBackup;

interface DatabaseRestore
{
    public function restore(DatabaseBackup $backup): void;
}


