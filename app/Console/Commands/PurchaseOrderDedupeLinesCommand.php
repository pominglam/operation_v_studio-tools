<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PurchaseOrders\PurchaseOrderDedupeLinesService;
use Illuminate\Console\Command;

final class PurchaseOrderDedupeLinesCommand extends Command
{
    protected $signature = 'purchase-orders:dedupe-lines
                            {--po= : Limit to a purchase order UUID}
                            {--execute : Apply merges (default is dry-run)}
                            {--yes : Skip confirmation when using --execute}';

    protected $description = 'Merge duplicate purchase order lines (same product_id) using qty-weighted unit costs.';

    public function handle(PurchaseOrderDedupeLinesService $service): int
    {
        $dryRun = ! (bool) $this->option('execute');
        $poUuid = $this->option('po');
        $poUuid = is_string($poUuid) ? trim($poUuid) : '';

        if ($dryRun) {
            $this->info('Dry run — no database changes. Pass --execute to apply merges.');
        } else {
            if (! (bool) $this->option('yes') && ! $this->confirm('Merge duplicate PO lines in the database?', false)) {
                $this->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        $report = $poUuid !== ''
            ? $service->dedupeByUuid($poUuid, $dryRun)
            : $service->dedupeAll($dryRun);

        if ($report === []) {
            $this->info('No duplicate PO lines found.');

            return self::SUCCESS;
        }

        $rows = array_map(static fn (array $entry): array => [
            $entry['purchase_order_uuid'],
            $entry['sku'],
            $entry['survivor_item_id'],
            implode(',', $entry['removed_item_ids']),
            $entry['cost_mismatch'] ? 'yes' : 'no',
            $entry['merged_qty_received'] ?? '',
        ], $report);

        $this->table(
            ['PO UUID', 'SKU', 'Survivor item id', 'Removed item ids', 'Cost mismatch', 'Merged qty received'],
            $rows,
        );

        $this->info(sprintf(
            '%s %d duplicate group(s).',
            $dryRun ? 'Would merge' : 'Merged',
            count($report),
        ));

        return self::SUCCESS;
    }
}
