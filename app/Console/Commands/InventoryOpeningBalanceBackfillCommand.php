<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inventory\InventoryOpeningBalanceBackfillService;
use Illuminate\Console\Command;

final class InventoryOpeningBalanceBackfillCommand extends Command
{
    protected $signature = 'inventory:backfill-opening-lots
        {--force : Run even if inventory_lots already has rows (dangerous)}
        {--yes : Do not prompt; assume yes}';

    protected $description = 'Backfill opening inventory lots from current products.available_qty (creates synthetic POs).';

    public function handle(InventoryOpeningBalanceBackfillService $service): int
    {
        $force = (bool) $this->option('force');
        $yes = (bool) $this->option('yes');

        $this->warn('This will create synthetic purchase orders/items and opening inventory lots from current product state.');
        $this->warn('If you rerun without --force, it will do nothing when lots already exist.');

        if (! $yes && ! $this->confirm('Proceed?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $service->backfill($force);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Backfill complete.');
        $this->table(
            ['purchase_orders', 'purchase_order_items', 'inventory_lots'],
            [[(string) $result['purchase_orders'], (string) $result['purchase_order_items'], (string) $result['inventory_lots']]],
        );

        return self::SUCCESS;
    }
}
