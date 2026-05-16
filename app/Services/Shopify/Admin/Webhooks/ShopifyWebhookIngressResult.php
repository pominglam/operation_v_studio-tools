<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Webhooks;

use App\Models\Shopify\ShopifyWebhookLog;

final readonly class ShopifyWebhookIngressResult
{
    public function __construct(
        public int $httpStatus,
        public bool $verified,
        public ShopifyWebhookLog $log,
    ) {}
}
