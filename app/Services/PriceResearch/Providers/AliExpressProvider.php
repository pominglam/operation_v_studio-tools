<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use App\Services\PriceResearch\FxRateService;
use App\Services\PriceResearch\Http\AliExpressScraperClient;
use Throwable;

final class AliExpressProvider implements CompetitorPriceProvider
{
    public function __construct(
        private readonly AliExpressScraperClient $scraper,
        private readonly FxRateService $fx,
    ) {}

    public function siteKey(): string
    {
        return 'aliexpress';
    }

    public function siteName(): string
    {
        return config('price_research.sites.aliexpress.name', 'AliExpress');
    }

    public function lookup(Product $product): PriceLookupResult
    {
        $term = $this->termForProduct($product);
        if ($term === null) {
            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        }

        try {
            $json = $this->scraper->searchAndScrape([
                'term' => $term,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
            ]);

            $status = (string) ($json['status'] ?? 'error');
            if ($status === 'not_found') {
                return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
            }
            if ($status !== 'found') {
                $msg = (string) ($json['error_message'] ?? 'AliExpress lookup failed');

                return PriceLookupResult::error($this->siteKey(), $this->siteName(), $msg);
            }

            $price = is_numeric($json['price'] ?? null) ? (float) $json['price'] : null;
            $original = is_numeric($json['original_price'] ?? null) ? (float) $json['original_price'] : null;
            $currency = strtoupper((string) ($json['currency'] ?? ''));
            $url = isset($json['product_url']) ? (string) $json['product_url'] : null;
            $availability = isset($json['availability']) ? (string) $json['availability'] : null;

            if ($price === null) {
                return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
            }

            // Normalize to CAD for storage/display.
            if ($currency !== '' && $currency !== 'CAD') {
                $rate = $this->fx->rate($currency, 'CAD');
                $price = round($price * $rate, 2);
                if ($original !== null) {
                    $original = round($original * $rate, 2);
                }
                $currency = 'CAD';
            }

            return PriceLookupResult::found(
                $this->siteKey(),
                $this->siteName(),
                $price,
                $original,
                'CAD',
                $url,
                in_array($availability, ['in_stock', 'sold_out'], true) ? $availability : null,
            );
        } catch (Throwable $e) {
            return PriceLookupResult::error($this->siteKey(), $this->siteName(), $e->getMessage());
        }
    }

    private function termForProduct(Product $product): ?string
    {
        $sku = trim((string) ($product->sku ?? ''));
        $barcode = trim((string) ($product->barcode ?? ''));
        $desc = trim((string) ($product->description ?? ''));

        // Prefer SKU + a short vendor hint if present (helps AliExpress search relevance).
        if ($sku !== '') {
            return $desc !== '' ? 'Stedi '.$sku : $sku;
        }
        if ($barcode !== '') {
            return $barcode;
        }
        if ($desc !== '') {
            return mb_substr($desc, 0, 80);
        }

        return null;
    }
}
