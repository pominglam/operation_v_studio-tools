<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PurchaseOrders\PurchaseOrderWorkflowExportShopifyContentFinalizeService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowExportShopifyContentQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PurchaseOrderWorkflowExportShopifyContentFinalizeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $purchaseOrderUuid,
        public string $batchId,
    ) {}

    public function handle(
        PurchaseOrderWorkflowExportShopifyContentFinalizeService $finalize,
        PurchaseOrderWorkflowExportShopifyContentQueueService $queue,
    ): void {
        try {
            $finalize->finalize($this->purchaseOrderUuid, $this->batchId);
        } catch (\Throwable $e) {
            Log::error('po_workflow_export_shopify_content.finalize_failed', [
                'purchase_order_uuid' => $this->purchaseOrderUuid,
                'batch_id' => $this->batchId,
                'message' => $e->getMessage(),
            ]);

            $queue->storeFinalizeResult($this->batchId, ['message' => $e->getMessage()], 'failed');
        }
    }
}
