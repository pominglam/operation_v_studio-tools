<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;

final class MeeplemartProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'meeplemart';
    }

    public function siteName(): string
    {
        return config('price_research.sites.meeplemart.name', 'Meeplemart');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.meeplemart.base_url', 'https://www.meeplemart.com');
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        if (($product->sku ?? '') !== '') {
            return (string) $product->sku;
        }

        if (($product->barcode ?? '') !== '') {
            return (string) $product->barcode;
        }

        $desc = trim((string) ($product->description ?? ''));
        if ($desc !== '') {
            // Strip local annotations like "(edited)" which can slow down / break search matching.
            $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
            $desc = trim(preg_replace('/\\s+/', ' ', $desc) ?? $desc);
            return mb_substr($desc, 0, 80);
        }

        return null;
    }

    /**
     * Meeplemart search endpoint:
     * https://www.meeplemart.com/store/Search.aspx?SearchTerms=...
     *
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        $q = rawurlencode($term);

        return [
            "{$base}/store/Search.aspx?SearchTerms={$q}",
        ];
    }
}


