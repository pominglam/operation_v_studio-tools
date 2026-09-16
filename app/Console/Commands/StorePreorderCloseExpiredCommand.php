<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\StorePreorders\StorePreorderExpireService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'store-preorders:close-expired')]
final class StorePreorderCloseExpiredCommand extends Command
{
    protected $signature = 'store-preorders:close-expired';

    protected $description = 'Close open store preorders whose closing date is before today (America/Toronto) using the same close path as the UI.';

    public function handle(StorePreorderExpireService $expire): int
    {
        $result = $expire->closeEndedWindows();
        $this->table(['metric', 'value'], [
            ['found', (string) $result->found],
            ['closed', (string) $result->closed],
            ['shopify_pushed', (string) $result->shopifyPushed],
            ['shopify_failed', (string) $result->shopifyFailed],
        ]);

        foreach ($result->errors as $error) {
            $sku = $error['sku'] !== '' ? $error['sku'] : '(unknown)';
            $this->warn($sku.': '.$error['message']);
        }

        if ($result->found === 0) {
            $this->info('No open store preorders are past their closing date.');

            return self::SUCCESS;
        }

        $this->info('Closed store preorders whose window ended before today.');

        return $result->shopifyFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
