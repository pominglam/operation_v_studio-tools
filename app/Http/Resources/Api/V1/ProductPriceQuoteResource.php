<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ProductPriceQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<ProductPriceQuote> */
final class ProductPriceQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductPriceQuote $quote */
        $quote = $this->resource;

        return [
            'site_key' => $quote->site_key,
            'site_name' => $quote->site_name,
            'status' => $quote->status,
            'availability' => $quote->availability,
            'currency' => $quote->currency,
            'price' => $quote->price,
            'original_price' => $quote->original_price,
            'product_url' => $quote->product_url,
            'error_message' => $quote->error_message,
            'fetched_at' => $quote->fetched_at->toISOString(),
        ];
    }
}


