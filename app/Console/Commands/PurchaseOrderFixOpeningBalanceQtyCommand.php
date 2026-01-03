<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderFixOpeningBalanceQtyCommand extends Command
{
    protected $signature = 'purchase-orders:fix-opening-balance-qty {--yes : Do not prompt; assume yes}';

    protected $description = 'Backfills qty_ordered/qty_shipped on opening balance purchase_order_items using qty_received.';

    public function handle(): int
    {
        $yes = (bool) $this->option('yes');

        $this->warn('This will update existing opening-balance POs to populate qty_ordered/qty_shipped from qty_received where missing.');

        if (! $yes && ! $this->confirm('Proceed?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $updated = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.notes', 'like', 'Opening balance backfill%')
            ->where(function ($q): void {
                $q->whereNull('purchase_order_items.qty_ordered')
                    ->orWhereNull('purchase_order_items.qty_shipped');
            })
            ->update([
                'qty_ordered' => DB::raw('qty_received'),
                'qty_shipped' => DB::raw('qty_received'),
                'purchase_order_items.updated_at' => now(),
            ]);

        $this->info("Updated {$updated} purchase_order_items row(s).");

        return self::SUCCESS;
    }
}


