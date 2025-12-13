<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\Product;
use App\Models\ProductPriceQuote;
use Illuminate\Support\Collection;

final class EloquentProductPriceQuoteRepository implements ProductPriceQuoteRepository
{
    public function upsertForProduct(Product $product, array $attributes): ProductPriceQuote
    {
        /** @var ProductPriceQuote $quote */
        $quote = ProductPriceQuote::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'site_key' => $attributes['site_key'],
            ],
            [
                'site_name' => $attributes['site_name'],
                'status' => $attributes['status'],
                'availability' => $attributes['availability'],
                'currency' => $attributes['currency'],
                'price' => $attributes['price'],
                'original_price' => $attributes['original_price'],
                'product_url' => $attributes['product_url'],
                'error_message' => $attributes['error_message'],
                'fetched_at' => $attributes['fetched_at'],
            ],
        );

        return $quote;
    }

    public function listLatestForProduct(Product $product): Collection
    {
        return ProductPriceQuote::query()
            ->where('product_id', $product->id)
            ->orderBy('site_key')
            ->get();
    }
}


