<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;

final class PandaHobbyProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'panda_hobby';
    }

    public function siteName(): string
    {
        return config('price_research.sites.panda_hobby.name', 'Panda Hobby');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.panda_hobby.base_url', 'https://pandahobby.ca');
    }

    protected function maxCandidateProductUrlsToCheck(): int
    {
        return 6;
    }

    public function lookup(Product $product): PriceLookupResult
    {
        $fromSuggest = $this->lookupViaShopifySuggestJson($product);
        if ($fromSuggest instanceof PriceLookupResult) {
            return $fromSuggest;
        }

        return parent::lookup($product);
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    protected function orderCandidateProductUrls(Product $product, array $links): array
    {
        usort($links, function (string $a, string $b) use ($product): int {
            return $this->scoreProductCandidateText($b, $product) <=> $this->scoreProductCandidateText($a, $product);
        });

        return $links;
    }
}
