<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<Product> */
final class ProductPriceResearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        /** @var CarbonImmutable|null $at */
        $at = $product->price_researched_at?->toImmutable();
        $ttlDays = max(1, (int) config('price_research.ttl_days', 14));
        $expired = $at === null || $at->lt(CarbonImmutable::now()->subDays($ttlDays));

        return [
            'id' => $product->uuid,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'price_researched_at' => $product->price_researched_at?->toISOString(),
            'expired' => $expired,
            'filled' => $product->filled_qty,
            'available' => $product->available_qty,
            'cost' => $product->price,
            'selling_price' => $product->sellingPrice?->selling_price,
            'quotes' => ProductPriceQuoteResource::collection($product->priceQuotes),
        ];
    }
}
