<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Shopify\Admin\Write\ShopifyStorePreordersCollectionReorderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ReorderStorePreordersCollectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'shopify';

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ShopifyStorePreordersCollectionReorderService $reorder): void
    {
        $result = $reorder->reorderByCloseDate();
        if ($result->attempted) {
            return;
        }

        Log::channel('shopify')->warning('shopify.store_preorder.collection_reorder.job_skipped', [
            'reason' => $result->skippedReason,
        ]);
    }
}
