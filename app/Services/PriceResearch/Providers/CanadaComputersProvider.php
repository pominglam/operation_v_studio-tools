<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

final class CanadaComputersProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'canada_computers';
    }

    public function siteName(): string
    {
        return config('price_research.sites.canada_computers.name', 'Canada Computers');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.canada_computers.base_url', 'https://www.canadacomputers.com');
    }

    /**
     * CanadaComputers search uses /en/search?s={term}&t=1
     *
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        $q = rawurlencode($term);

        return [
            "{$base}/en/search?s={$q}&t=1",
        ];
    }
}
