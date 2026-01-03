<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PurchaseOrders\OpeningBalancePurchaseOrderReassignmentService;
use Illuminate\Console\Command;

final class PurchaseOrderReassignOpeningBalanceItemCommand extends Command
{
    protected $signature = 'purchase-orders:reassign-opening-balance-item
        {sku : Product SKU to reassign}
        {--yes : Do not prompt; assume yes}';

    protected $description = 'Moves a product opening-balance PO item to the correct opening-balance PO based on current product vendor.';

    public function handle(OpeningBalancePurchaseOrderReassignmentService $service): int
    {
        $sku = (string) $this->argument('sku');
        $yes = (bool) $this->option('yes');

        $this->warn("This will move opening-balance PO items for SKU '{$sku}' to the correct vendor opening-balance PO.");

        if (! $yes && ! $this->confirm('Proceed?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        try {
            $result = $service->reassignForSku($sku);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Reassignment complete.');
        $this->table(
            ['sku', 'product_uuid', 'moved_items', 'from_po_uuids', 'to_po_uuid'],
            [[
                $result['sku'],
                $result['product_uuid'],
                (string) $result['moved_items'],
                implode(', ', $result['from_po_uuids']),
                (string) ($result['to_po_uuid'] ?? ''),
            ]],
        );

        return self::SUCCESS;
    }
}


