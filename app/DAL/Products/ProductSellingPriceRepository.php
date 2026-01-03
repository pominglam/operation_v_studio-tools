<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\Product;
use App\Models\ProductSellingPrice;

interface ProductSellingPriceRepository
{
    public function upsertForProduct(Product $product, ?string $sellingPrice, string $currency = 'CAD'): ProductSellingPrice;

    /**
     * @return array<int, int> Product IDs that have a non-null selling_price
     */
    public function productIdsWithSellingPriceSet(): array;
}
