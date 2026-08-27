<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;

final class HobbySenseProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'hobby_sense';
    }

    public function siteName(): string
    {
        return config('price_research.sites.hobby_sense.name', 'Hobby Sense');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.hobby_sense.base_url', 'https://hobbysense.ca');
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        $desc = trim((string) ($product->description ?? ''));
        if ($desc !== '') {
            $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
            $desc = trim(preg_replace('/\\s+/', ' ', $desc) ?? $desc);

            return mb_substr($desc, 0, 80);
        }

        if (($product->sku ?? '') !== '') {
            return (string) $product->sku;
        }

        if (($product->barcode ?? '') !== '') {
            return (string) $product->barcode;
        }

        return null;
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
}
