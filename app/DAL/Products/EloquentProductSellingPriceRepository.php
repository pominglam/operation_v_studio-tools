<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\Product;
use App\Models\ProductSellingPrice;

final class EloquentProductSellingPriceRepository implements ProductSellingPriceRepository
{
    public function upsertForProduct(Product $product, ?string $sellingPrice, string $currency = 'CAD'): ProductSellingPrice
    {
        /** @var ProductSellingPrice $row */
        $row = ProductSellingPrice::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'product_uuid' => $product->uuid,
                'selling_price' => $sellingPrice,
                'currency' => $currency,
            ],
        );

        return $row;
    }

    public function productIdsWithSellingPriceSet(): array
    {
        /** @var array<int, int> $ids */
        $ids = ProductSellingPrice::query()
            ->whereNotNull('selling_price')
            ->where('selling_price', '<>', '')
            ->orderBy('product_id')
            ->pluck('product_id')
            ->all();

        return array_values($ids);
    }
}
