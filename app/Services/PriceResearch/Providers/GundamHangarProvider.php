<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class GundamHangarProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'gundam_hangar';
    }

    public function siteName(): string
    {
        return config('price_research.sites.gundam_hangar.name', 'Gundam Hangar');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.gundam_hangar.base_url', 'https://gundamhangar.com');
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        $desc = trim((string) ($product->description ?? ''));
        if ($desc !== '') {
            // GundamHangar API redirects endlessly for some encoded characters (notably "/" from "1/144").
            // Keep the query "search engine friendly" by stripping punctuation and collapsing whitespace.
            $desc = preg_replace('/[^a-z0-9\\s]+/i', ' ', $desc) ?? $desc;
            $desc = trim(preg_replace('/\\s+/', ' ', $desc) ?? $desc);

            return mb_substr($desc, 0, 72);
        }

        if (($product->sku ?? '') !== '') {
            return (string) $product->sku;
        }

        if (($product->barcode ?? '') !== '') {
            return (string) $product->barcode;
        }

        return null;
    }

    public function lookup(Product $product): PriceLookupResult
    {
        $terms = $this->searchTermsForProduct($product);
        if ($terms === []) {
            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        }

        try {
            foreach (array_slice($terms, 0, 4) as $term) {
                $url = $this->apiUrlForSearch($term);
                try {
                    $res = $this->http->get($url, [
                        'Accept' => 'application/json, text/plain, */*',
                    ], $this->siteKey());
                    if (! $res->successful()) {
                        continue;
                    }
                } catch (\Throwable) {
                    // Treat request issues as a miss for this term; try the next term.
                    continue;
                }

                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode($res->body(), true);
                if (! is_array($decoded)) {
                    continue;
                }

                $items = Arr::get($decoded, 'data', []);
                if (! is_array($items) || $items === []) {
                    continue;
                }

                $best = $this->pickBestMatch($product, $items);
                if ($best === null) {
                    continue;
                }

                $slug = (string) ($best['slug'] ?? '');
                $productUrl = $slug !== '' ? rtrim($this->baseUrl(), '/').'/canadian-gundam-store/product/'.$slug : null;

                $final = is_numeric($best['final_price'] ?? null) ? (float) $best['final_price'] : null;
                $list = is_numeric($best['price'] ?? null) ? (float) $best['price'] : null;

                $price = ($final !== null && $final > 0) ? $final : $list;
                $originalPrice = ($final !== null && $final > 0) ? $list : null;

                $stock = is_numeric($best['stock'] ?? null) ? (int) $best['stock'] : null;
                $availability = $stock === null ? null : ($stock > 0 ? 'in_stock' : 'sold_out');

                if ($price === null) {
                    continue;
                }

                return PriceLookupResult::found(
                    $this->siteKey(),
                    $this->siteName(),
                    $price,
                    $originalPrice,
                    'CAD',
                    $productUrl,
                    $availability,
                );
            }

            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        } catch (\Throwable $e) {
            return PriceLookupResult::error($this->siteKey(), $this->siteName(), $e->getMessage());
        }
    }

    private function apiUrlForSearch(string $term): string
    {
        // rawurlencode encodes "/" as "%2F"; some queries require that to match correctly.
        $q = rawurlencode($term);

        return "https://server.gundamhangar.com/api/products?limit=16&page=1&category=gundam-mobile-suit-kit&outofstock=1&search={$q}";
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<string, mixed>|null
     */
    private function pickBestMatch(Product $product, array $items): ?array
    {
        $needleTokens = $this->tokenize((string) ($product->description ?? ''));
        if ($needleTokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = (string) ($item['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $haystackTokens = $this->tokenize($title);
            if ($haystackTokens === []) {
                continue;
            }

            $common = array_intersect($needleTokens, $haystackTokens);
            $score = count($common) / max(1, count($needleTokens));

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $item;
            }
        }

        // Require at least a small overlap to avoid obvious false positives.
        if ($bestScore < 0.25) {
            return null;
        }

        return $best;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^a-z0-9\\s\\/\\-#]+/i', ' ', $text) ?? $text;
        $parts = preg_split('/\\s+/', $text) ?: [];

        $stop = [
            'the', 'and', 'for', 'with', 'ver', 'version',
        ];

        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (in_array($p, $stop, true)) {
                continue;
            }
            $tokens[] = $p;
        }

        return array_values(array_unique($tokens));
    }
}
