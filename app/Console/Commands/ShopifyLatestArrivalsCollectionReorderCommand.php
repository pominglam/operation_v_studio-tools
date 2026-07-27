<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Shopify\Admin\Write\ShopifyLatestArrivalsCollectionReorderService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'shopify:latest-arrivals-collection-reorder')]
final class ShopifyLatestArrivalsCollectionReorderCommand extends Command
{
    protected $signature = 'shopify:latest-arrivals-collection-reorder';

    protected $description = 'Reorder the Latest Arrivals Shopify collection from ERP catalog order (waits for Shopify job).';

    public function handle(ShopifyLatestArrivalsCollectionReorderService $reorder): int
    {
        try {
            $result = $reorder->reorderFromCatalogOrder();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (($result['attempted'] ?? false) !== true) {
            $this->error('Collection reorder skipped: '.((string) ($result['skipped_reason'] ?? 'unknown')));

            return self::FAILURE;
        }

        $this->table(['metric', 'value'], [
            ['collection_gid', (string) ($result['collection_gid'] ?? '')],
            ['product_count', (string) ($result['product_count'] ?? 0)],
            ['moves_sent', (string) ($result['moves_sent'] ?? 0)],
            ['job_id', (string) ($result['job_id'] ?? '')],
            ['job_done', ($result['job_done'] ?? false) ? 'yes' : 'no'],
            ['job_wait_timed_out', ($result['job_wait_timed_out'] ?? false) ? 'yes' : 'no'],
            ['skipped_reason', (string) ($result['skipped_reason'] ?? '')],
        ]);

        if (($result['job_wait_timed_out'] ?? false) === true) {
            $this->warn('Shopify reorder job did not finish within the wait window. Refresh the storefront in a minute or re-run this command.');

            return self::FAILURE;
        }

        $this->info('Latest Arrivals collection reorder complete.');

        return self::SUCCESS;
    }
}
