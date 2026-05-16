<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Sync\ShopifyErpSyncCoordinator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:sync')]
final class ShopifySyncCommand extends Command
{
    protected $signature = 'shopify:sync
        {target=full : One of full,locations,products,inventory_levels,orders,customers,collections}';

    protected $description = 'Run Phase 1 read-only Shopify Admin GraphQL sync into local ERP tables.';

    public function handle(ShopifyErpSyncCoordinator $coordinator): int
    {
        $target = strtolower(trim((string) $this->argument('target')));
        $allowed = ['full', 'locations', 'products', 'inventory_levels', 'orders', 'customers', 'collections'];
        if (! in_array($target, $allowed, true)) {
            $this->error('Unknown target.');

            return self::INVALID;
        }

        $this->info("Starting Shopify sync target: {$target}");
        try {
            $log = $coordinator->sync($target);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Finished with status {$log->status}");
        $this->table(
            ['metric', 'value'],
            [
                ['id', (string) $log->id],
                ['records_fetched', (string) $log->records_fetched],
                ['records_created', (string) $log->records_created],
                ['records_updated', (string) $log->records_updated],
                ['records_failed', (string) $log->records_failed],
                ['duration_ms', (string) $log->duration_ms],
                ['error', (string) ($log->error_summary ?? '')],
            ],
        );

        return self::SUCCESS;
    }
}
