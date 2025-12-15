<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;

interface CompetitorPriceProvider
{
    public function siteKey(): string;

    public function siteName(): string;

    public function lookup(Product $product): PriceLookupResult;
}
