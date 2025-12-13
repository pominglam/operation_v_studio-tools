<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

final class HobbyBeeProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'hobby_bee';
    }

    public function siteName(): string
    {
        return config('price_research.sites.hobby_bee.name', 'Hobby Bee');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.hobby_bee.base_url', 'https://hobby-bee.com');
    }

    /**
     * Hobby Bee uses Shopify app search at /a/search?q=...
     *
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        $q = rawurlencode($term);

        return [
            "{$base}/a/search?q={$q}",
        ];
    }
}


