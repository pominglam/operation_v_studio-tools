<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Shopify\ShopifyWebhookReceived;
use App\Services\Shopify\Admin\Webhooks\ShopifyOrderWebhookHandler;

final class RecordShopifyWebhookDispatch
{
    public function __construct(
        private readonly ShopifyOrderWebhookHandler $orderWebhooks,
    ) {}

    public function handle(ShopifyWebhookReceived $event): void
    {
        if (! $event->log->verification_ok) {
            return;
        }

        $this->orderWebhooks->handle($event->log);
    }
}
