<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductSellingPriceRepository;
use App\Models\ProductSellingPrice;

final class ProductSellingPriceService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductSellingPriceRepository $sellingPrices,
    ) {}

    public function upsertForProductUuid(string $productUuid, ?string $sellingPrice, string $currency = 'CAD'): ProductSellingPrice
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        return $this->sellingPrices->upsertForProduct($product, $sellingPrice, $currency);
    }
}
