<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Webhooks;

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Models\Shopify\ShopifyWebhookLog;

final class ShopifyOrderWebhookHandler
{
    /** @var list<string> */
    private const array ORDER_TOPICS = [
        'orders/create',
        'orders/updated',
        'orders/cancelled',
        'orders/delete',
    ];

    public function handle(ShopifyWebhookLog $log): void
    {
        if (! in_array($log->topic, self::ORDER_TOPICS, true)) {
            return;
        }

        $orderGid = $this->resolveOrderGid($log);
        if ($orderGid === null) {
            $log->forceFill([
                'processing_status' => 'failed',
                'processing_error' => 'missing_order_gid',
            ])->save();

            return;
        }

        ProcessShopifyOrderWebhookJob::dispatch($log->id, $orderGid);
        $log->forceFill(['processing_status' => 'dispatched'])->save();
    }

    private function resolveOrderGid(ShopifyWebhookLog $log): ?string
    {
        $payload = $log->payload_json;
        if (! is_array($payload)) {
            return null;
        }

        foreach (['admin_graphql_api_id', 'id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && str_starts_with($value, 'gid://shopify/Order/')) {
                return $value;
            }
        }

        $legacy = $payload['id'] ?? null;
        if (is_numeric($legacy)) {
            return 'gid://shopify/Order/'.(string) $legacy;
        }

        return null;
    }
}
