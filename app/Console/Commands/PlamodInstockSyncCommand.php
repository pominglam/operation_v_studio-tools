<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Plamod\PlamodInstockDispatchService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'plamod:instock-sync')]
final class PlamodInstockSyncCommand extends Command
{
    protected $signature = 'plamod:instock-sync';

    protected $description = 'Queue a Plamod in-stock catalog refresh for the restock proposal.';

    public function handle(PlamodInstockDispatchService $dispatch): int
    {
        $result = $dispatch->dispatch(skipIfActive: true);
        if (($result['ok'] ?? false) !== true) {
            $message = (string) ($result['error_message'] ?? 'Could not queue PLAMOD in-stock refresh.');
            $this->warn($message);

            return (($result['skipped'] ?? false) === true) ? self::SUCCESS : self::FAILURE;
        }

        $this->info('PLAMOD in-stock refresh queued.');
        $this->line('sync_log_id: '.(string) ($result['sync_log_id'] ?? ''));

        return self::SUCCESS;
    }
}
