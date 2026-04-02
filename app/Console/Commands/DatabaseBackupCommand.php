<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maintenance\DatabaseBackupService;
use Illuminate\Console\Command;

final class DatabaseBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--yes : Do not prompt; assume yes}
        {--description= : Description for this backup (shown in Maintenance UI)}
        {--created-by=manual : created_by for this backup (manual|system|cursor)}';

    protected $description = 'Create a database + images backup bundle into storage/backups.';

    public function handle(\App\Services\Maintenance\DatabaseBackupManagerService $service): int
    {
        $yes = (bool) $this->option('yes');
        $description = (string) ($this->option('description') ?? '');
        $createdBy = (string) ($this->option('created-by') ?? 'manual');

        $this->warn('This will create a database + images backup bundle into storage/backups.');

        if (! $yes && ! $this->confirm('Proceed?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $backup = $service->create($description, $createdBy);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup created.');
        $this->table(
            ['driver', 'filename', 'storage_path', 'description'],
            [[$backup->driver, $backup->filename, $backup->storage_path, $backup->description]],
        );

        return self::SUCCESS;
    }
}




