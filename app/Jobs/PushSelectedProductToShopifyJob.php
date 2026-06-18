<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DAL\Products\ProductRepository;
use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Services\Jobs\JobBatchItemService;
use App\Services\Shopify\Admin\Write\ShopifyInventoryLocationResolver;
use App\Services\Shopify\Admin\Write\ShopifyProductUpsertFromErpService;
use App\Services\Shopify\CloudflaredTunnel;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PushSelectedProductToShopifyJob implements ShouldQueue
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

    /**
     * @param  array<string, bool>  $pushOptions
     */
    public function __construct(
        public string $syncUuid,
        public string $productUuid,
        public array $pushOptions,
    ) {}

    public function handle(
        ProductRepository $products,
        ShopifyProductUpsertFromErpService $shopifyUpsert,
        ShopifyInventoryLocationResolver $locationResolver,
        CloudflaredTunnel $tunnel,
        JobBatchItemService $batchItems,
    ): void {
        $batchId = $this->batch()?->id;
        if ($this->batch()?->cancelled()) {
            if (is_string($batchId) && $batchId !== '') {
                $batchItems->markSkipped($batchId, $this->productUuid, 'cancelled');
            }

            return;
        }

        $options = ShopifyProductPushOptionsDTO::fromArray($this->pushOptions);

        if (is_string($batchId) && $batchId !== '') {
            $batchItems->markRunning($batchId, $this->productUuid, $this->syncUuid);
            $batchItems->appendDebugLog($batchId, $this->productUuid, '[job] shopify_push start');
        }

        try {
            $product = $products->listForShopifyContentExportByUuids([$this->productUuid])->first();
            if ($product === null) {
                throw new \RuntimeException('Product not found.');
            }

            $tunnelStatus = $tunnel->status();
            $tunnelUrl = is_string($tunnelStatus['tunnel_url'] ?? null) ? trim($tunnelStatus['tunnel_url']) : '';
            $imagesEnabled = ($tunnelStatus['running'] ?? false) === true && $tunnelUrl !== '';
            $tunnelBaseUrl = $options->images && $imagesEnabled ? $tunnelUrl : null;
            $locationGid = $options->quantities ? $locationResolver->resolveLocationGid() : '';

            $usedHandles = [];
            $result = $shopifyUpsert->upsertFromProduct(
                $product,
                $tunnelBaseUrl,
                $locationGid,
                $usedHandles,
                $options,
            );

            if (is_string($batchId) && $batchId !== '') {
                $batchItems->appendDebugLog($batchId, $this->productUuid, sprintf(
                    '[job] shopify_push done action=%s gid=%s images=%s',
                    $result['action'],
                    $result['shopify_gid'],
                    ($options->images && $imagesEnabled) ? 'enabled' : 'skipped',
                ));
                $batchItems->markSucceeded($batchId, $this->productUuid);
            }

            Log::info('products.shopify_push.completed', [
                'sync_uuid' => $this->syncUuid,
                'product_uuid' => $this->productUuid,
                'action' => $result['action'],
                'shopify_gid' => $result['shopify_gid'],
            ]);
        } catch (\Throwable $e) {
            if (is_string($batchId) && $batchId !== '') {
                $batchItems->appendDebugLog($batchId, $this->productUuid, '[job] shopify_push error message='.$e->getMessage());
                $batchItems->markFailed($batchId, $this->productUuid, $e->getMessage());
            }

            Log::warning('products.shopify_push.failed', [
                'sync_uuid' => $this->syncUuid,
                'product_uuid' => $this->productUuid,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
