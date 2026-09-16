<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\Models\Shopify\ShopifyOrderLineItem;
use App\Models\StorePreorder;

final class StorePreorderOrderLineDetector
{
    public function isStorePreorder(ShopifyOrderLineItem $line): bool
    {
        if ($line->product_id !== null
            && StorePreorder::query()->where('product_id', $line->product_id)->exists()) {
            return true;
        }

        $sku = is_string($line->sku) ? trim($line->sku) : '';
        if ($sku !== '' && StorePreorder::query()->where('plamod_sku', $sku)->exists()) {
            return true;
        }

        return $this->payloadLooksLikeStorePreorder($line->payload_json);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function payloadLooksLikeStorePreorder(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $attrs = $payload['customAttributes'] ?? $payload['custom_attributes'] ?? [];
        if (! is_array($attrs)) {
            return false;
        }

        foreach ($attrs as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $key = strtolower(trim((string) ($attr['key'] ?? '')));
            if ($key === '(po)' || $key === 'pre-order' || $key === '_po') {
                return true;
            }
        }

        return false;
    }
}
