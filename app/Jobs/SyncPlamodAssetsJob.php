<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Jobs\JobBatchItemService;
use App\Services\Products\PlamodAssetSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class SyncPlamodAssetsJob implements ShouldQueue
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
        return [new SkipIfBatchCancelled];
    }

    public function __construct(
        public string $syncUuid,
        public string $productUuid,
        public bool $attemptPlamodAssets = false,
    ) {
        // Dedicated queue for long-running PDP sync work (so it doesn't get stuck behind unrelated default backlog).
        $this->onQueue('pdp_sync');
    }

    public function handle(PlamodAssetSyncService $service, JobBatchItemService $batchItems): void
    {
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

        try {
            $result = $service->syncByProductUuid($this->productUuid, $this->attemptPlamodAssets);

            if (is_string($batchId) && $batchId !== '') {
                $batchItems->markSucceeded($batchId, $this->productUuid);
            }
        } catch (\Throwable $e) {
            if (is_string($batchId) && $batchId !== '') {
                $batchItems->markFailed($batchId, $this->productUuid, $e->getMessage());
            }
            throw $e;
        }

        Log::info('plamod.sync.completed', [
            'sync_uuid' => $this->syncUuid,
            'product_uuid' => $this->productUuid,
            'backup_created' => $result->backupCreated,
            'assets_count' => count($result->assets),
            'attempt_plamod_assets' => $this->attemptPlamodAssets,
        ]);
    }
}
