<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use Illuminate\Support\Str;

final class CanadianGundamProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'canadian_gundam';
    }

    public function siteName(): string
    {
        return config('price_research.sites.canadian_gundam.name', 'Canadian Gundam');
    }

    protected function baseUrl(): string
    {
        // Prefer the canonical host to reduce redirects/timeouts.
        return config('price_research.sites.canadian_gundam.base_url', 'https://www.canadiangundam.com');
    }

    protected function maxCandidateProductUrlsToCheck(): int
    {
        // CanadianGundam search pages often return many close matches; check a few more PDPs.
        return 6;
    }

    /**
     * CanadianGundam search results are already ranked well; avoid re-ordering by URL heuristics.
     *
     * @param array<int, string> $links
     * @return array<int, string>
     */
    protected function orderCandidateProductUrls(Product $product, array $links): array
    {
        return $links;
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        // Prefer description for CanadianGundam's PrestaShop search. It performs well with the site's own ranking,
        // but we strip local annotations like "(edited)" to avoid polluting the query.
        $desc = trim((string) ($product->description ?? ''));
        if ($desc !== '') {
            $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
            $desc = trim(preg_replace('/\\s+/', ' ', $desc) ?? $desc);
            return mb_substr($desc, 0, 80);
        }

        if (($product->barcode ?? '') !== '') {
            return (string) $product->barcode;
        }

        if (($product->sku ?? '') !== '') {
            return (string) $product->sku;
        }

        return null;
    }

    protected function htmlLikelyMatchesProduct(string $html, Product $product): bool
    {
        // CanadianGundam pages contain lots of global nav text that can trigger generic token matching.
        // Scope matching to the product title to avoid false positives (e.g. Pokemon pages).
        $title = $this->extractProductTitle($html);
        if ($title !== null) {
            $titleLower = mb_strtolower($title);

            if (($product->barcode ?? '') !== '' && str_contains($titleLower, mb_strtolower((string) $product->barcode))) {
                return true;
            }

            if (($product->sku ?? '') !== '' && str_contains($titleLower, mb_strtolower((string) $product->sku))) {
                return true;
            }

            $desc = trim((string) ($product->description ?? ''));
            $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
            $descLower = mb_strtolower($desc);

            // If we expect "gundam", require it in the title.
            if (str_contains($descLower, 'gundam') && ! str_contains($titleLower, 'gundam')) {
                return false;
            }

            $tokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
            $tokens = array_values(array_filter($tokens, static fn (string $t): bool => mb_strlen($t) >= 4));
            $hits = 0;
            foreach (array_slice($tokens, 0, 8) as $t) {
                if (str_contains($titleLower, mb_strtolower($t))) {
                    $hits++;
                }
            }

            return $hits >= 2;
        }

        return parent::htmlLikelyMatchesProduct($html, $product);
    }

    private function extractProductTitle(string $html): ?string
    {
        if (preg_match('/<h1\\b[^>]*>(.*?)<\\/h1>/is', $html, $m) !== 1) {
            return null;
        }

        $raw = html_entity_decode(strip_tags((string) ($m[1] ?? '')));
        $raw = trim(preg_replace('/\\s+/', ' ', $raw) ?? $raw);
        if ($raw === '') {
            return null;
        }

        // Avoid giant blobs if markup is weird.
        return Str::limit($raw, 200, '');
    }

    /**
     * CanadianGundam is PrestaShop and uses a querystring search endpoint.
     *
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        $q = rawurlencode($term);

        return [
            // Omit submit_search to avoid odd redirect behavior observed on some responses.
            "{$base}/search?controller=search&orderby=position&orderway=desc&search_query={$q}",
        ];
    }
}


