<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Models\Shopify\ShopifyWebhookLog;
use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessShopifyOrderWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $webhookLogId,
        public string $orderGid,
    ) {}

    public function handle(ShopifyOrderReconcileService $reconcile): void
    {
        /** @var ShopifyWebhookLog|null $log */
        $log = ShopifyWebhookLog::query()->find($this->webhookLogId);

        try {
            $reconcile->fetchAndUpsertOrderGid($this->orderGid);
            if ($log !== null) {
                $log->forceFill(['processing_status' => 'processed'])->save();
            }
        } catch (Throwable $e) {
            if ($log !== null) {
                $log->forceFill([
                    'processing_status' => 'failed',
                    'processing_error' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();
            }

            throw $e;
        }
    }
}
