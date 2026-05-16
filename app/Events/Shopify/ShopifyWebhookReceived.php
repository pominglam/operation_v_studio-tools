<?php

declare(strict_types=1);

namespace App\Events\Shopify;

use App\Models\Shopify\ShopifyWebhookLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ShopifyWebhookReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ShopifyWebhookLog $log,
    ) {}
}
