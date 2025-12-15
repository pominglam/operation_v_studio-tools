<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\Product;
use App\Models\ProductSellingPrice;

interface ProductSellingPriceRepository
{
    public function upsertForProduct(Product $product, ?string $sellingPrice, string $currency = 'CAD'): ProductSellingPrice;
}

