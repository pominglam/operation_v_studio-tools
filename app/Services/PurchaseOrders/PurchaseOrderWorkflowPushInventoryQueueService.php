<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Jobs\PurchaseOrderWorkflowPushInventoryFinalizeJob;
use App\Jobs\PushSelectedProductToShopifyJob;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPushInventoryException;
use App\Services\Shopify\Admin\Write\ShopifyWriteScopeGuard;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class PurchaseOrderWorkflowPushInventoryQueueService
{
    public const string BATCH_NAME = 'po_workflow_push_inventory';

    private const string CACHE_PREFIX = 'po_workflow_push_inventory:';

    public function __construct(
        private readonly PurchaseOrderWorkflowPushInventoryService $pushInventory,
        private readonly PurchaseOrderProductScopeService $poScope,
        private readonly ProductRepository $products,
        private readonly JobBatchItemRepository $batchItems,
        private readonly ShopifyWriteScopeGuard $scopeGuard,
    ) {}

    /**
     * @return array{batch_id: string, queued: int}
     */
    public function queuePush(string $purchaseOrderUuid): array
    {
        $po = $this->poScope->findPoOrFail($purchaseOrderUuid);
        if ($po->received_date === null) {
            throw new PurchaseOrderWorkflowPushInventoryException(
                'Set a received date on this purchase order before pushing to Shopify. Unreceived POs are ignored for Latest Arrivals storefront ordering.',
            );
        }

        $preview = $this->pushInventory->preview($purchaseOrderUuid);
        $uuids = $preview['product_uuids'];
        if ($uuids === []) {
            throw new PurchaseOrderWorkflowPushInventoryException('No eligible products to push.');
        }

        try {
            $this->scopeGuard->assertWriteProductsScope();
            $this->scopeGuard->assertWriteInventoryScope();
        } catch (ShopifyAdminConfigurationException $e) {
            throw new PurchaseOrderWorkflowPushInventoryException($e->getMessage());
        }

        $pushOptions = ShopifyProductPushOptionsDTO::allEnabled()->toArray();
        $optionsLine = '[job] push_options=info,images,quantities,price,publish_status,sales_channels';

        $jobs = [];
        $itemProducts = [];
        $existing = $this->products->findByUuids($uuids)->keyBy('uuid');

        foreach ($uuids as $uuid) {
            $product = $existing->get($uuid);
            if ($product === null) {
                continue;
            }

            $jobs[] = new PushSelectedProductToShopifyJob(
                (string) Str::uuid(),
                (string) $uuid,
                $pushOptions,
            );

            $itemProducts[] = [
                'product_uuid' => (string) $uuid,
                'sku' => is_string($product->sku ?? null) ? (string) $product->sku : null,
                'vendor' => is_string($product->vendor ?? null) ? (string) $product->vendor : null,
                'debug_log' => $optionsLine,
            ];
        }

        if ($jobs === []) {
            throw new PurchaseOrderWorkflowPushInventoryException('No eligible products to push.');
        }

        $batch = Bus::batch($jobs)
            ->name(self::BATCH_NAME)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($purchaseOrderUuid): void {
                PurchaseOrderWorkflowPushInventoryFinalizeJob::dispatch($purchaseOrderUuid, $batch->id);
            })
            ->dispatch();

        $this->batchItems->insertQueued($batch->id, $itemProducts);

        Cache::put($this->cacheKey($batch->id), [
            'purchase_order_uuid' => $purchaseOrderUuid,
            'finalize_status' => 'pending',
            'preview_meta' => [
                'location_gid' => $preview['location_gid'],
                'location_name' => $preview['location_name'],
                'images_enabled' => (bool) ($preview['images_enabled'] ?? false),
                'skipped' => count($preview['products']) - count($uuids),
            ],
        ], now()->addHours(24));

        return [
            'batch_id' => $batch->id,
            'queued' => count($itemProducts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pushStatus(string $purchaseOrderUuid, string $batchId): array
    {
        $cached = Cache::get($this->cacheKey($batchId));
        if (! is_array($cached) || ($cached['purchase_order_uuid'] ?? '') !== $purchaseOrderUuid) {
            throw new PurchaseOrderWorkflowPushInventoryException('Push batch not found for this purchase order.');
        }

        $batch = Bus::findBatch($batchId);
        if ($batch === null) {
            throw new PurchaseOrderWorkflowPushInventoryException('Push batch not found.');
        }

        $total = (int) $batch->totalJobs;
        $pending = (int) $batch->pendingJobs;
        $failed = (int) $batch->failedJobs;
        $processed = max(0, $total - $pending);
        $percent = $total > 0 ? (int) round(($processed / $total) * 100) : 100;

        $batchPayload = [
            'id' => $batch->id,
            'name' => $batch->name,
            'total_jobs' => $total,
            'pending_jobs' => $pending,
            'processed_jobs' => $processed,
            'failed_jobs' => $failed,
            'progress_percent' => $percent,
            'cancelled' => (bool) $batch->cancelled(),
            'finished_at' => $batch->finishedAt?->toISOString(),
        ];

        $finalizeStatus = is_string($cached['finalize_status'] ?? null) ? $cached['finalize_status'] : 'pending';
        if ($finalizeStatus === 'complete' && is_array($cached['result'] ?? null)) {
            /** @var array<string, mixed> $result */
            $result = $cached['result'];

            return [
                'phase' => 'complete',
                'batch' => $batchPayload,
                ...$result,
            ];
        }

        if ($finalizeStatus === 'failed') {
            return [
                'phase' => 'failed',
                'batch' => $batchPayload,
                'message' => is_string($cached['error'] ?? null) ? $cached['error'] : 'Push finalize failed.',
            ];
        }

        if ($batch->finished()) {
            return [
                'phase' => 'finalizing',
                'batch' => $batchPayload,
            ];
        }

        return [
            'phase' => 'pushing',
            'batch' => $batchPayload,
        ];
    }

    public function cacheKey(string $batchId): string
    {
        return self::CACHE_PREFIX.$batchId;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function storeFinalizeResult(string $batchId, array $result, string $finalizeStatus = 'complete'): void
    {
        $cached = Cache::get($this->cacheKey($batchId));
        if (! is_array($cached)) {
            return;
        }

        $cached['finalize_status'] = $finalizeStatus;
        if ($finalizeStatus === 'complete') {
            $cached['result'] = $result;
        } else {
            $cached['error'] = is_string($result['message'] ?? null) ? $result['message'] : 'Push finalize failed.';
        }

        Cache::put($this->cacheKey($batchId), $cached, now()->addHours(24));
    }
}
