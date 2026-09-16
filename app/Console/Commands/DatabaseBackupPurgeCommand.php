<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Maintenance\DatabaseBackupRetentionService;
use Illuminate\Console\Command;

final class DatabaseBackupPurgeCommand extends Command
{
    protected $signature = 'db:backup:purge
        {--yes : Do not prompt; assume yes}
        {--dry-run : Show what would be purged without deleting files or rows}';

    protected $description = 'Purge old database backups per retention policy (recent dailies + weekly history).';

    public function handle(DatabaseBackupRetentionService $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $yes = (bool) $this->option('yes');

        $recent = config('database_backup.retention.recent_days');
        $weekly = config('database_backup.retention.weekly_days');
        $minimum = config('database_backup.retention.minimum_count');

        $this->line("Retention: keep all from last {$recent} days, one per week for {$weekly} days, minimum {$minimum} backups.");

        if ($dryRun) {
            $this->warn('Dry run — no files or rows will be deleted.');
        } elseif (! $yes && ! $this->confirm('Proceed with purge?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $retention->purge($dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run complete.' : 'Purge complete.');
        $this->table(
            ['total_before', 'kept', 'purged'],
            [[$result->totalBefore, $result->keptCount, $result->purgedCount]],
        );

        if ($result->purgedCount > 0) {
            $this->line('Purged UUIDs: '.implode(', ', $result->purgedUuids));
        }

        return self::SUCCESS;
    }
}
