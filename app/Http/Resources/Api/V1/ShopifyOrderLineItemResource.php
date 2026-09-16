<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Models\Shopify\ShopifyOrderLineItem;
use App\Services\StorePreorders\StorePreorderOrderLineDetector;
use App\Services\StorePreorders\StorePreorderShopifyPushOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<ShopifyOrderLineItem> */
final class ShopifyOrderLineItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShopifyOrderLineItem $line */
        $line = $this->resource;
        $product = $line->relationLoaded('product') ? $line->product : null;
        $description = $product instanceof Product && is_string($product->description)
            ? $product->description
            : null;
        $isStorePreorder = app(StorePreorderOrderLineDetector::class)->isStorePreorder($line);
        if ($isStorePreorder && is_string($description)) {
            $description = StorePreorderShopifyPushOverride::withLineTitleSuffix($description);
        }

        return [
            'id' => $line->id,
            'sku' => $line->sku,
            'quantity' => (int) $line->quantity,
            'description' => $description,
            'is_store_preorder' => $isStorePreorder,
            'sold_on' => $line->sold_on?->toDateString(),
        ];
    }
}
