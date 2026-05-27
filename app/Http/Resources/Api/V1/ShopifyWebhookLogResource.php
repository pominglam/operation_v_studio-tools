<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Shopify\ShopifyWebhookLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<ShopifyWebhookLog> */
final class ShopifyWebhookLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShopifyWebhookLog $log */
        $log = $this->resource;
        $includePayload = (bool) $request->boolean('include_payload');

        return [
            'id' => $log->id,
            'shop_domain' => $log->shop_domain,
            'topic' => $log->topic,
            'shopify_webhook_id' => $log->shopify_webhook_id,
            'request_id' => $log->request_id,
            'verification_ok' => (bool) $log->verification_ok,
            'processing_status' => $log->processing_status,
            'verification_error' => $log->verification_error,
            'processing_error' => $log->processing_error,
            'payload_json' => $includePayload ? $log->payload_json : null,
            'created_at' => optional($log->created_at)->toISOString(),
            'updated_at' => optional($log->updated_at)->toISOString(),
        ];
    }
}
