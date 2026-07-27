<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Jobs\JobBatchItemRepository;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\Shopify\Admin\Write\ShopifyLatestArrivalsCollectionReorderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

final class PurchaseOrderWorkflowPushInventoryFinalizeService
{
    public function __construct(
        private readonly PurchaseOrderWorkflowPushInventoryQueueService $queue,
        private readonly PurchaseOrderWorkflowVerifyService $verify,
        private readonly JobBatchItemRepository $batchItems,
        private readonly ShopifyLatestArrivalsCollectionReorderService $collectionReorder,
    ) {}

    public function finalize(string $purchaseOrderUuid, string $batchId): void
    {
        $cacheKey = $this->queue->cacheKey($batchId);
        $cached = Cache::get($cacheKey);
        if (! is_array($cached) || ($cached['purchase_order_uuid'] ?? '') !== $purchaseOrderUuid) {
            $this->queue->storeFinalizeResult($batchId, ['message' => 'Push batch metadata missing.'], 'failed');

            return;
        }

        $batch = Bus::findBatch($batchId);
        if ($batch === null) {
            $this->queue->storeFinalizeResult($batchId, ['message' => 'Push batch not found.'], 'failed');

            return;
        }

        /** @var array<string, mixed> $previewMeta */
        $previewMeta = is_array($cached['preview_meta'] ?? null) ? $cached['preview_meta'] : [];

        $itemSummary = $this->batchItems->getSummary($batchId, 500);
        $errors = [];
        foreach ($itemSummary['done'] as $row) {
            if (($row['status'] ?? '') === 'failed') {
                $errors[] = [
                    'sku' => is_string($row['sku'] ?? null) ? $row['sku'] : '',
                    'message' => is_string($row['last_error'] ?? null) ? $row['last_error'] : 'Push failed.',
                ];
            }
        }

        $failed = (int) $batch->failedJobs;
        $succeededFromItems = (int) ($itemSummary['counts']['succeeded'] ?? 0);
        $succeeded = $succeededFromItems > 0
            ? $succeededFromItems
            : max(0, (int) $batch->totalJobs - $failed);

        $collectionReorder = [
            'attempted' => false,
            'collection_gid' => null,
            'product_count' => 0,
            'moves_sent' => 0,
            'job_id' => null,
            'job_done' => false,
            'job_wait_timed_out' => false,
            'skipped_reason' => 'push_had_failures',
        ];
        if ($failed === 0) {
            try {
                $collectionReorder = $this->collectionReorder->reorderFromCatalogOrder();
            } catch (\Throwable $e) {
                $collectionReorder = [
                    'attempted' => false,
                    'collection_gid' => config('latest_arrival.collection_gid'),
                    'product_count' => 0,
                    'moves_sent' => 0,
                    'job_id' => null,
                    'job_done' => false,
                    'job_wait_timed_out' => false,
                    'skipped_reason' => 'reorder_failed: '.$e->getMessage(),
                ];
            }
        }

        $verification = $this->verify->verifyAndAutoCheck($purchaseOrderUuid);

        $summary = [
            'location_gid' => is_string($previewMeta['location_gid'] ?? null) ? $previewMeta['location_gid'] : '',
            'location_name' => is_string($previewMeta['location_name'] ?? null) ? $previewMeta['location_name'] : null,
            'created' => 0,
            'updated' => $succeeded,
            'failed' => $failed,
            'skipped' => (int) ($previewMeta['skipped'] ?? 0),
            'images_enabled' => (bool) ($previewMeta['images_enabled'] ?? false),
            'errors' => $errors,
            'collection_reorder' => $collectionReorder,
        ];

        $this->queue->storeFinalizeResult($batchId, [
            'summary' => $summary,
            'steps' => $verification['steps'],
            'purchase_order' => PurchaseOrderResource::make($verification['purchase_order'])->resolve(),
        ]);
    }
}
