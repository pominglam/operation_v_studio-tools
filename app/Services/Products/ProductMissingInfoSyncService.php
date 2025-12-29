<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DTOs\Products\MissingInfoSyncResultDTO;
use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\Jobs\SyncPlamodAssetsJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class ProductMissingInfoSyncService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
    ) {}

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $missing
     */
    public function syncMissingInfo(?string $search, array $types, array $vendors, array $missing, bool $dryRun = false): MissingInfoSyncResultDTO
    {
        // Only PDP-related missing flags can be auto-synced (via Plamod/HLJ pipeline).
        $missing = array_values(array_unique(array_filter($missing, static fn (string $v): bool => in_array($v, ['pdp_description', 'pdp_images'], true))));
        if ($missing === []) {
            $missing = ['pdp_description', 'pdp_images'];
        }

        // Bulk “sync missing PDP info” should attempt to fetch Plamod assets when the user is missing images.
        // This is best-effort: if the product does not exist on Plamod (or a ZIP is unavailable), the job may still
        // fill description via HLJ and otherwise no-op.
        $attemptPlamodAssets = in_array('pdp_images', $missing, true);

        $batchId = null;
        $count = 0;
        $jobs = [];
        $itemProducts = [];

        $this->products
            ->cursorForMissingInfo($search, $types, $vendors, $missing)
            ->chunk(200)
            ->each(function ($chunk) use (&$count, $dryRun, &$jobs, $attemptPlamodAssets, &$itemProducts): void {
                foreach ($chunk as $p) {
                    $count++;
                    if ($dryRun) {
                        continue;
                    }
                    $jobs[] = new SyncPlamodAssetsJob((string) Str::uuid(), (string) $p->uuid, $attemptPlamodAssets);
                    $itemProducts[] = [
                        'product_uuid' => (string) $p->uuid,
                        'sku' => is_string($p->sku ?? null) ? (string) $p->sku : null,
                        'vendor' => is_string($p->vendor ?? null) ? (string) $p->vendor : null,
                    ];
                }
            });

        if (! $dryRun && $jobs !== []) {
            $batch = Bus::batch($jobs)
                ->name('sync_missing_pdp_info')
                ->onQueue('pdp_sync')
                // Do not cancel the entire run if one product fails; record failures and keep going.
                ->allowFailures()
                ->dispatch();
            $batchId = $batch->id;

            $this->batchItems->insertQueued($batchId, $itemProducts);
        }

        return new MissingInfoSyncResultDTO(
            queued: $count,
            dryRun: $dryRun,
            batchId: $batchId,
        );
    }
}


