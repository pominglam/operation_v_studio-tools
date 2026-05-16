<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Webhooks;

final readonly class ShopifyWebhookIngressDto
{
    public function __construct(
        public string $rawBody,
        public string $topic,
        public string $shopDomain,
        public ?string $webhookId,
        public ?string $requestId,
        public string $hmacHeader,
    ) {}
}
