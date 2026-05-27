<?php

declare(strict_types=1);

namespace App\Jobs\Shopify;

use App\Services\Shopify\Admin\Orders\ShopifyOrderReconcileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ShopifyOrderReconcileJob implements ShouldQueue
{
    use Queueable;

    public function handle(ShopifyOrderReconcileService $reconcile): void
    {
        $reconcile->reconcileIncremental();
    }
}
