<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Plamod\PlamodPreorderDispatchService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'plamod:preorders-sync')]
final class PlamodPreordersSyncCommand extends Command
{
    protected $signature = 'plamod:preorders-sync';

    protected $description = 'Queue a Plamod preorders CSV import, image cleanup, and image downloads.';

    public function handle(PlamodPreorderDispatchService $dispatch): int
    {
        $result = $dispatch->dispatch();
        $this->info('Plamod preorders sync queued.');
        $this->line('sync_log_id: '.(string) ($result['sync_log_id'] ?? ''));

        return self::SUCCESS;
    }
}
