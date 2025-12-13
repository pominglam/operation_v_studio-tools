<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;

final class HobbyWholesaleProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'hobby_wholesale';
    }

    public function siteName(): string
    {
        return config('price_research.sites.hobby_wholesale.name', 'HobbyWholesale');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.hobby_wholesale.base_url', 'https://hobbywholesale.com');
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
            return mb_substr($desc, 0, 72);
        }

        return null;
    }

    /**
     * HobbyWholesale uses the /search/{term} route.
     *
     * Example:
     * https://hobbywholesale.com/search/HG+1%2F144+%2313+Gundam+Astray+Blue+Frame
     *
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        $q = rawurlencode($term);

        return [
            "{$base}/search/{$q}",
        ];
    }
}


