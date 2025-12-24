<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maintenance\DatabaseBackupService;
use Illuminate\Console\Command;

final class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--yes : Do not prompt; assume yes}';

    protected $description = 'Create a database backup (schema + data) into storage/backups.';

    public function handle(DatabaseBackupService $service): int
    {
        $yes = (bool) $this->option('yes');

        $this->warn('This will create a database backup (schema + data) into storage/backups.');

        if (! $yes && ! $this->confirm('Proceed?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $service->backup();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup created.');
        $this->table(
            ['driver', 'filename', 'path'],
            [[$result['driver'], $result['filename'], $result['path']]],
        );

        return self::SUCCESS;
    }
}




