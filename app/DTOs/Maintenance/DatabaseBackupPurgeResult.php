<?php

declare(strict_types=1);

namespace App\DTOs\Maintenance;

final readonly class DatabaseBackupPurgeResult
{
    /**
     * @param  list<string>  $keptUuids
     * @param  list<string>  $purgedUuids
     */
    public function __construct(
        public int $totalBefore,
        public int $keptCount,
        public int $purgedCount,
        public array $keptUuids,
        public array $purgedUuids,
        public bool $dryRun,
    ) {}
}
