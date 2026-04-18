<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Jobs\JobBatchItemService;
use App\Services\Products\PlamodAssetFilenameService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;

final class RenameSelectedProductAssetsJob implements ShouldQueue
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
    ) {
        $this->onQueue('pdp_sync');
    }

    public function handle(PlamodAssetFilenameService $renamer, JobBatchItemService $batchItems): void
    {
        $batchId = $this->batch()?->id;
        if (! is_string($batchId) || trim($batchId) === '') {
            // Still perform the rename, but we can't report progress.
            $renamer->renameImageAssetsForProductUuid($this->productUuid);

            return;
        }

        if ($this->batch()?->cancelled()) {
            $batchItems->markSkipped($batchId, $this->productUuid, 'cancelled');

            return;
        }

        $batchItems->markRunning($batchId, $this->productUuid, $this->syncUuid);
        $batchItems->appendDebugLog($batchId, $this->productUuid, '[job] type=rename_assets');

        try {
            $t0 = microtime(true);
            $res = $renamer->renameImageAssetsForProductUuid($this->productUuid);
            $durationMs = (int) round((microtime(true) - $t0) * 1000);

            $batchItems->appendDebugLog($batchId, $this->productUuid, '[rename][done] duration_ms='.$durationMs);
            $batchItems->appendDebugLog($batchId, $this->productUuid, '[rename][counts] renamed='.(int) ($res['renamed'] ?? 0).' skipped='.(int) ($res['skipped'] ?? 0).' missing='.(int) ($res['missing_files'] ?? 0));

            $errors = $res['errors'] ?? [];
            if (is_array($errors) && $errors !== []) {
                $limit = 20;
                foreach (array_slice($errors, 0, $limit) as $line) {
                    if (! is_string($line) || trim($line) === '') {
                        continue;
                    }
                    $batchItems->appendDebugLog($batchId, $this->productUuid, '[rename][error] '.trim($line));
                }
                if (count($errors) > $limit) {
                    $batchItems->appendDebugLog($batchId, $this->productUuid, '[rename][error] (truncated)');
                }
            }

            $batchItems->markSucceeded($batchId, $this->productUuid);
        } catch (\Throwable $e) {
            $batchItems->appendDebugLog($batchId, $this->productUuid, '[rename][fatal] '.$e->getMessage());
            $batchItems->markFailed($batchId, $this->productUuid, $e->getMessage());
        }
    }
}
