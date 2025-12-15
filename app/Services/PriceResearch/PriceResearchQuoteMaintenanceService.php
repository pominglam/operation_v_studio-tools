<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\DAL\Products\ProductRepository;

final class PriceResearchQuoteMaintenanceService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductPriceQuoteRepository $quotes,
    ) {
    }

    public function deleteQuote(string $productUuid, string $siteKey): bool
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        return $this->quotes->deleteForProductAndSiteKey($product, $siteKey);
    }
}


