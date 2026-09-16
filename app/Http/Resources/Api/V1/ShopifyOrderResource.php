<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Shopify\ShopifyOrder;
use App\Services\Shopify\Admin\Orders\ShopifyOrderChannelPresenter;
use App\Support\Shopify\ShopifyOrderAdminUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<ShopifyOrder> */
final class ShopifyOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShopifyOrder $order */
        $order = $this->resource;
        $presenter = app(ShopifyOrderChannelPresenter::class);
        $channel = $presenter->present($order);
        $processedBy = $presenter->processedBy($order);
        $salesChannel = $presenter->salesChannel($order);
        $subtotal = $order->subtotal_shop_amount;
        $lineCount = $order->line_items_count ?? null;
        $storeEvent = $order->relationLoaded('storeEvent') ? $order->storeEvent : null;

        return [
            'id' => $order->id,
            'name' => $order->name,
            'customer_email' => is_string($order->customer_email) && $order->customer_email !== ''
                ? $order->customer_email
                : null,
            'customer_phone' => is_string($order->customer_phone) && $order->customer_phone !== ''
                ? $order->customer_phone
                : null,
            'shopify_admin_url' => ShopifyOrderAdminUrl::forLegacyId(
                is_string($order->legacy_numeric_id) ? $order->legacy_numeric_id : null,
            ),
            'ordered_at' => $order->ordered_at_shop_tz?->toISOString(),
            'source_name' => $order->source_name,
            'channel_name' => $order->channel_name,
            'channel_key' => $channel['key'],
            'channel_label' => $channel['label'],
            'processed_by_key' => $processedBy['key'] ?? null,
            'processed_by_label' => $processedBy['label'] ?? null,
            'sales_channel_key' => $salesChannel['key'],
            'sales_channel_label' => $salesChannel['label'],
            'store_event_id' => is_string($storeEvent?->uuid) ? $storeEvent->uuid : null,
            'store_event_name' => is_string($storeEvent?->name) ? $storeEvent->name : null,
            'pos_user_id' => $order->pos_user_id !== null ? (int) $order->pos_user_id : null,
            'financial_status' => $order->display_financial_status,
            'fulfillment_status' => $order->display_fulfillment_status,
            'subtotal' => $subtotal !== null ? number_format((float) $subtotal, 2, '.', '') : null,
            'cancelled_at' => $order->cancelled_at?->toISOString(),
            'line_item_count' => is_numeric($lineCount) ? (int) $lineCount : null,
            'has_store_preorder' => (bool) ($order->has_store_preorder ?? false),
            'lines' => $order->relationLoaded('lineItems')
                ? ShopifyOrderLineItemResource::collection($order->lineItems)
                : null,
        ];
    }
}
