<?php

declare(strict_types=1);

namespace App\Services\Shopify\Admin\Orders;

use App\Contracts\Shopify\ShopifyAdminGraphQlClientInterface;
use App\Models\Shopify\ShopifyOrder;
use App\Models\Shopify\ShopifyOrderLineItem;
use App\Services\Shopify\Admin\GraphQl\ShopifyAdminGraphQlMutations;
use App\Services\StorePreorders\StorePreorderOrderLineDetector;
use App\Services\StorePreorders\StorePreorderShopifyPushOverride;
use Illuminate\Support\Facades\Log;

final class ShopifyOrderStorePreorderTagger
{
    public function __construct(
        private readonly ShopifyAdminGraphQlClientInterface $client,
        private readonly StorePreorderOrderLineDetector $detector,
    ) {}

    public function tagIfNeeded(ShopifyOrder $order): void
    {
        $order->loadMissing('lineItems');
        $hasPreorder = $order->lineItems->contains(
            fn (ShopifyOrderLineItem $line): bool => $this->detector->isStorePreorder($line),
        );
        if (! $hasPreorder) {
            return;
        }

        $existing = $order->payload_json['tags'] ?? [];
        if (is_array($existing)) {
            foreach ($existing as $tag) {
                if (strcasecmp(trim((string) $tag), StorePreorderShopifyPushOverride::ORDER_TAG) === 0) {
                    return;
                }
            }
        }

        try {
            $this->client->query(ShopifyAdminGraphQlMutations::TAGS_ADD, [
                'id' => $order->gid,
                'tags' => [StorePreorderShopifyPushOverride::ORDER_TAG],
            ]);
        } catch (\Throwable $e) {
            Log::channel('shopify')->warning('shopify.order.preorder_tag_failed', [
                'gid' => $order->gid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
