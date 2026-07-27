<?php

declare(strict_types=1);

namespace App\Services\PurchaseOrders;

use App\DAL\Jobs\JobBatchItemRepository;
use App\DAL\Products\ProductRepository;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Models\Shopify\ShopifyProductVariant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

final class PurchaseOrderWorkflowExportShopifyContentFinalizeService
{
    public function __construct(
        private readonly PurchaseOrderWorkflowExportShopifyContentQueueService $queue,
        private readonly PurchaseOrderWorkflowVerifyService $verify,
        private readonly JobBatchItemRepository $batchItems,
        private readonly ProductRepository $products,
    ) {}

    public function finalize(string $purchaseOrderUuid, string $batchId): void
    {
        $cacheKey = $this->queue->cacheKey($batchId);
        $cached = Cache::get($cacheKey);
        if (! is_array($cached) || ($cached['purchase_order_uuid'] ?? '') !== $purchaseOrderUuid) {
            $this->queue->storeFinalizeResult($batchId, ['message' => 'Export batch metadata missing.'], 'failed');

            return;
        }

        $batch = Bus::findBatch($batchId);
        if ($batch === null) {
            $this->queue->storeFinalizeResult($batchId, ['message' => 'Export batch not found.'], 'failed');

            return;
        }

        /** @var array<string, mixed> $previewMeta */
        $previewMeta = is_array($cached['preview_meta'] ?? null) ? $cached['preview_meta'] : [];

        $itemSummary = $this->batchItems->getSummary($batchId, 500);
        $errors = [];
        $succeededUuids = [];

        foreach ($itemSummary['done'] as $row) {
            $status = is_string($row['status'] ?? null) ? $row['status'] : '';
            $productUuid = is_string($row['product_uuid'] ?? null) ? $row['product_uuid'] : '';
            if ($status === 'failed') {
                $errors[] = [
                    'sku' => is_string($row['sku'] ?? null) ? $row['sku'] : '',
                    'message' => is_string($row['last_error'] ?? null) ? $row['last_error'] : 'Export failed.',
                ];
            } elseif ($status === 'succeeded' && $productUuid !== '') {
                $succeededUuids[] = $productUuid;
            }
        }

        $failed = (int) $batch->failedJobs;
        $succeededFromItems = (int) ($itemSummary['counts']['succeeded'] ?? 0);
        $created = $succeededFromItems > 0
            ? $succeededFromItems
            : max(0, (int) $batch->totalJobs - $failed);

        $results = [];
        $resultUuids = $succeededUuids;
        if ($resultUuids === [] && $created > 0) {
            $resultUuids = $this->batchItems->listProductUuidsByStatus($batchId, ['succeeded', 'queued', 'running']);
        }

        if ($resultUuids !== []) {
            $products = $this->products->findByUuids($resultUuids);
            foreach ($products as $product) {
                $handle = is_string($product->handle ?? null) ? trim($product->handle) : '';
                if ($handle === '') {
                    continue;
                }

                $shopifyGid = '';
                $variant = ShopifyProductVariant::query()
                    ->where('sku', (string) $product->sku)
                    ->first();
                if ($variant !== null && is_string($variant->product_gid ?? null)) {
                    $shopifyGid = $variant->product_gid;
                }

                $results[] = [
                    'sku' => (string) $product->sku,
                    'handle' => $handle,
                    'shopify_gid' => $shopifyGid,
                ];
            }
        }

        $verification = $this->verify->verifyAndAutoCheck($purchaseOrderUuid);

        $summary = [
            'created' => $created,
            'failed' => $failed,
            'skipped' => (int) ($previewMeta['skipped'] ?? 0),
            'images_enabled' => (bool) ($previewMeta['images_enabled'] ?? false),
            'results' => $results,
            'errors' => $errors,
        ];

        $this->queue->storeFinalizeResult($batchId, [
            'summary' => $summary,
            'steps' => $verification['steps'],
            'purchase_order' => PurchaseOrderResource::make($verification['purchase_order'])->resolve(),
        ]);
    }
}
