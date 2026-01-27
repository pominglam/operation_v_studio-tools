<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DAL\Products\ProductRepository;
use App\Services\Jobs\JobBatchItemService;
use App\Services\Products\Bandai\BandaiContentSyncService;
use App\Services\Products\Hlj\HljContentSync;
use App\Services\Products\PlamodAssetSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncProductInfoJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SkipIfBatchCancelled()];
    }

    public function __construct(
        public string $syncUuid,
        public string $productUuid,
        public bool $attemptPlamodAssets = false,
    ) {
        $this->onQueue('pdp_sync');
    }

    public function handle(
        ProductRepository $products,
        PlamodAssetSyncService $plamod,
        HljContentSync $hlj,
        BandaiContentSyncService $bandai,
        JobBatchItemService $batchItems,
    ): void {
        $batchId = $this->batch()?->id;
        if ($this->batch()?->cancelled()) {
            if (is_string($batchId) && $batchId !== '') {
                $batchItems->markSkipped($batchId, $this->productUuid, 'cancelled');
            }
            return;
        }

        if (is_string($batchId) && $batchId !== '') {
            $batchItems->markRunning($batchId, $this->productUuid, $this->syncUuid);
        }

        $didWork = false;
        try {
            if ($this->attemptPlamodAssets) {
                $plamod->syncByProductUuid($this->productUuid, true);
                $didWork = true;
            } else {
                $product = $products->findByUuidOrFail($this->productUuid);
                $hlj->syncForProduct($product);
                $didWork = true;
            }

            $didWork = $bandai->syncByProductUuid($this->productUuid) || $didWork;

            if (is_string($batchId) && $batchId !== '') {
                if ($didWork) {
                    $batchItems->markSucceeded($batchId, $this->productUuid);
                } else {
                    $batchItems->markSkipped($batchId, $this->productUuid, 'no_sources_found');
                }
            }
        } catch (\Throwable $e) {
            if (is_string($batchId) && $batchId !== '') {
                $batchItems->markFailed($batchId, $this->productUuid, $e->getMessage());
            }
            throw $e;
        } finally {
            Log::info('product_info.sync.completed', [
                'sync_uuid' => $this->syncUuid,
                'product_uuid' => $this->productUuid,
                'attempt_plamod_assets' => $this->attemptPlamodAssets,
            ]);
        }
    }
}

