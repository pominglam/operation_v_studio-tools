<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyStorePreordersCollectionReorderService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:store-preorders-collection-reorder')]
final class ShopifyStorePreordersCollectionReorderCommand extends Command
{
    protected $signature = 'shopify:store-preorders-collection-reorder';

    protected $description = 'Reorder /collections/pre-orders by close date (open first, soonest first).';

    public function handle(ShopifyStorePreordersCollectionReorderService $reorder): int
    {
        try {
            $result = $reorder->reorderByCloseDate();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['metric', 'value'], [
            ['collection_gid', (string) ($result->collectionGid ?? '')],
            ['product_count', (string) $result->productCount],
            ['moves_sent', (string) $result->movesSent],
            ['job_id', (string) ($result->jobId ?? '')],
            ['job_done', $result->jobDone ? 'yes' : 'no'],
            ['job_wait_timed_out', $result->jobWaitTimedOut ? 'yes' : 'no'],
            ['skipped_reason', (string) ($result->skippedReason ?? '')],
        ]);

        if (! $result->attempted) {
            $this->error('Collection reorder skipped: '.((string) ($result->skippedReason ?? 'unknown')));

            return self::FAILURE;
        }

        if ($result->jobWaitTimedOut) {
            $this->warn('Shopify reorder job did not finish within the wait window. Refresh the storefront in a minute or re-run this command.');

            return self::FAILURE;
        }

        $this->info('Store preorders collection reorder complete.');

        return self::SUCCESS;
    }
}
