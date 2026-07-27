<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PurchaseOrders\PurchaseOrderWorkflowPushInventoryFinalizeService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowPushInventoryQueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class PurchaseOrderWorkflowPushInventoryFinalizeJob implements ShouldQueue
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
        PurchaseOrderWorkflowPushInventoryFinalizeService $finalize,
        PurchaseOrderWorkflowPushInventoryQueueService $queue,
    ): void {
        try {
            $finalize->finalize($this->purchaseOrderUuid, $this->batchId);
        } catch (\Throwable $e) {
            Log::error('po_workflow_push_inventory.finalize_failed', [
                'purchase_order_uuid' => $this->purchaseOrderUuid,
                'batch_id' => $this->batchId,
                'message' => $e->getMessage(),
            ]);

            $queue->storeFinalizeResult($this->batchId, ['message' => $e->getMessage()], 'failed');
        }
    }
}
