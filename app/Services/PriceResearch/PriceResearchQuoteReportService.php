<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\DAL\PriceResearch\PriceResearchQuoteReportRepository;
use App\DAL\PriceResearch\ProductLookupRepository;
use App\DAL\PriceResearch\ProductPriceQuoteRepository;
use App\Models\PriceResearchQuoteReport;
use App\Services\PriceResearch\Exceptions\QuoteNotFoundException;

final class PriceResearchQuoteReportService
{
    public function __construct(
        private readonly ProductLookupRepository $products,
        private readonly ProductPriceQuoteRepository $quotes,
        private readonly PriceResearchQuoteReportRepository $reports,
    ) {}

    public function report(string $productUuid, string $siteKey, ?string $note, ?string $runUuid): PriceResearchQuoteReport
    {
        $product = $this->products->findByUuidOrFail($productUuid);

        $quote = $this->quotes->findForProductAndSiteKey($product, $siteKey);
        if ($quote === null) {
            throw new QuoteNotFoundException('Quote not found for this product/site.');
        }

        return $this->reports->create([
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'sku' => $product->sku,
            'site_key' => $quote->site_key,
            'site_name' => $quote->site_name,
            'status' => $quote->status,
            'availability' => $quote->availability,
            'currency' => $quote->currency,
            'price' => $quote->price,
            'original_price' => $quote->original_price,
            'product_url' => $quote->product_url,
            'error_message' => $quote->error_message,
            'fetched_at' => $quote->fetched_at,
            'run_uuid' => $runUuid,
            'note' => $note,
        ]);
    }
}
