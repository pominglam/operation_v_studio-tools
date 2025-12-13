<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\Product;
use App\Models\ProductPriceQuote;
use Illuminate\Support\Collection;

interface ProductPriceQuoteRepository
{
    /**
     * @param array{
     *   site_key: string,
     *   site_name: string,
     *   status: string,
     *   availability: string|null,
     *   currency: string,
     *   price: float|null,
     *   original_price: float|null,
     *   product_url: string|null,
     *   error_message: string|null,
     *   fetched_at: \DateTimeInterface
     * } $attributes
     */
    public function upsertForProduct(Product $product, array $attributes): ProductPriceQuote;

    /**
     * @return Collection<int, ProductPriceQuote>
     */
    public function listLatestForProduct(Product $product): Collection;
}


