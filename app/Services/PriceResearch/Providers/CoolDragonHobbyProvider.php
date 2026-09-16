<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use App\Support\PriceResearch\CoolDragonSearchTerms;

final class CoolDragonHobbyProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'cool_dragon_hobby';
    }

    public function siteName(): string
    {
        return config('price_research.sites.cool_dragon_hobby.name', 'Cool Dragon Hobby');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.cool_dragon_hobby.base_url', 'https://cooldragonhobby.ca');
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));
        $desc = CoolDragonSearchTerms::fromTitle($desc);
        if ($desc !== '') {
            return mb_substr($desc, 0, 64);
        }

        return parent::searchTermForProduct($product);
    }

    /**
     * @return array<int, string>
     */
    protected function searchTermsForProduct(Product $product): array
    {
        $primary = $this->searchTermForProduct($product);
        if ($primary === null) {
            return [];
        }

        $terms = [$primary];
        $withoutBrand = trim(preg_replace('/^SNAA\s+/i', '', $primary) ?? $primary);
        if ($withoutBrand !== '' && $withoutBrand !== $primary) {
            $terms[] = $withoutBrand;
        }

        return $terms;
    }

    public function scoreProductCandidateText(string $candidateText, Product $product): int
    {
        $score = parent::scoreProductCandidateText($candidateText, $product);
        if (CoolDragonSearchTerms::titlesLikelyMatch($candidateText, (string) ($product->description ?? ''))) {
            return $score + 50;
        }

        return $score;
    }

    protected function htmlLikelyMatchesProduct(string $html, Product $product): bool
    {
        $title = $this->extractTitleForMatching($html);
        $haystack = mb_strtolower($title ?? $html);

        return CoolDragonSearchTerms::titlesLikelyMatch($haystack, (string) ($product->description ?? ''));
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
