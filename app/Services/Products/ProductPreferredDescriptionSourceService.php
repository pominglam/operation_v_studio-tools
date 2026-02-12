<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;

final class ProductPreferredDescriptionSourceService
{
    public function __construct(private readonly ProductRepository $products) {}

    public function setForProduct(string $productUuid, ?string $source): Product
    {
        $product = $this->products->findByUuidOrFail($productUuid);
        $source = is_string($source) ? trim($source) : null;
        $source = $source !== '' ? $source : null;

        $product->preferred_description_source = $source;
        $this->products->save($product);

        return $product;
    }
}

