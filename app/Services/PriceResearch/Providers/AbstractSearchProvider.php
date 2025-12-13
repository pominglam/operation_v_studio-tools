<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Support\HtmlPriceParser;
use Illuminate\Support\Arr;
use Throwable;

abstract class AbstractSearchProvider implements CompetitorPriceProvider
{
    public function __construct(
        protected readonly ExternalHtmlClient $http,
        protected readonly HtmlPriceParser $parser,
    ) {
    }

    abstract protected function baseUrl(): string;

    /**
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        // rawurlencode encodes "/" as "%2F" which some retailer search endpoints require.
        $q = rawurlencode($term);

        return [
            "{$base}/search?q={$q}",
            // Fallbacks. Keeping these limited helps bound runtime per provider.
            "{$base}/search?type=product&q={$q}",
        ];
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        if (($product->sku ?? '') !== '') {
            return $product->sku;
        }

        if (($product->barcode ?? '') !== '') {
            return $product->barcode;
        }

        $desc = trim((string) ($product->description ?? ''));
        if ($desc === '') {
            return null;
        }

        return mb_substr($desc, 0, 64);
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

        $sku = trim((string) ($product->sku ?? ''));
        if ($sku !== '' && ! in_array($sku, $terms, true)) {
            $terms[] = $sku;
        }

        $barcode = trim((string) ($product->barcode ?? ''));
        if ($barcode !== '' && ! in_array($barcode, $terms, true)) {
            $terms[] = $barcode;
        }

        // Finally, try the product name (description) as a fallback term. Many retailer searches only work
        // when the query is the human-readable product name (URL-encoded in searchUrlsForTerm()).
        $desc = trim((string) ($product->description ?? ''));
        if ($desc !== '') {
            $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
            $desc = trim(preg_replace('/\\s+/', ' ', $desc) ?? $desc);
            $desc = mb_substr($desc, 0, 96);
            if ($desc !== '' && ! in_array($desc, $terms, true)) {
                $terms[] = $desc;
            }
        }

        return $terms;
    }

    protected function htmlLikelyMatchesProduct(string $html, Product $product): bool
    {
        $haystack = mb_strtolower($html);

        // Prefer strong identifiers when present, but do NOT require them; some competitor PDPs do not
        // expose UPC/SKU in the HTML (e.g. Canadian Gundam). We'll fall back to a stricter token match.
        if (($product->barcode ?? '') !== '' && str_contains($haystack, mb_strtolower((string) $product->barcode))) {
            return true;
        }

        if (($product->sku ?? '') !== '' && str_contains($haystack, mb_strtolower((string) $product->sku))) {
            return true;
        }

        $desc = trim((string) ($product->description ?? ''));
        if ($desc === '') {
            return false;
        }

        // Match on meaningful tokens from the description (best-effort).
        // We intentionally allow small wording differences (missing 1–2 tokens) to avoid false "not found"
        // when competitor product titles vary slightly.
        $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
        $tokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
        $tokens = array_map(static fn (string $t): string => mb_strtolower($t), $tokens);

        // Drop common, low-signal tokens (reduces false positives and avoids requiring words like "gundam").
        $stop = ['gundam', 'bandai', 'hobby', 'model', 'kit'];
        $tokens = array_values(array_filter($tokens, static function (string $t) use ($stop): bool {
            if (mb_strlen($t) < 4) return false;
            return ! in_array($t, $stop, true);
        }));

        if ($tokens === []) {
            return false;
        }

        $hits = 0;
        foreach (array_slice($tokens, 0, 10) as $t) {
            if (str_contains($haystack, $t)) {
                $hits++;
            }
        }

        // Allow up to 2 missing tokens, but always require at least 2 hits.
        $minHits = max(2, count($tokens) - 2);
        return $hits >= $minHits;
    }

    public function lookup(Product $product): PriceLookupResult
    {
        $terms = $this->searchTermsForProduct($product);
        if ($terms === []) {
            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        }

        try {
            foreach (array_slice($terms, 0, 4) as $term) {
                foreach ($this->searchUrlsForTerm($term) as $searchUrl) {
                    $searchRes = $this->http->get($searchUrl, [], $this->siteKey());
                    if (! $searchRes->successful()) {
                        continue;
                    }

                    $links = $this->parser->extractCandidateProductUrls($searchRes->body(), $this->baseUrl());
                    $links = $this->orderCandidateProductUrls($product, $links);
                    foreach (Arr::take($links, $this->maxCandidateProductUrlsToCheck()) as $productUrl) {
                        $productRes = $this->http->get($productUrl, [], $this->siteKey());
                        if (! $productRes->successful()) {
                            continue;
                        }

                        if (! $this->htmlLikelyMatchesProduct($productRes->body(), $product)) {
                            continue;
                        }

                        $offer = $this->parser->extractPriceAndAvailabilityFromHtml($productRes->body());
                        if ($offer['price'] !== null) {
                            return PriceLookupResult::found(
                                $this->siteKey(),
                                $this->siteName(),
                                $offer['price'],
                                $offer['original_price'],
                                'CAD',
                                $productUrl,
                                $offer['availability'],
                            );
                        }
                    }
                }
            }

            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        } catch (Throwable $e) {
            return PriceLookupResult::error($this->siteKey(), $this->siteName(), $e->getMessage());
        }
    }

    protected function maxCandidateProductUrlsToCheck(): int
    {
        return 2;
    }

    /**
     * Prefer candidate URLs that contain the product identifiers (sku/barcode), to reduce false positives
     * when search pages return many close matches.
     *
     * @param array<int, string> $links
     * @return array<int, string>
     */
    protected function orderCandidateProductUrls(Product $product, array $links): array
    {
        $sku = mb_strtolower(trim((string) ($product->sku ?? '')));
        $barcode = mb_strtolower(trim((string) ($product->barcode ?? '')));
        $banSku = $sku !== '' ? 'ban'.$sku : '';

        usort($links, function (string $a, string $b) use ($sku, $barcode, $banSku): int {
            $aL = mb_strtolower($a);
            $bL = mb_strtolower($b);

            $aScore = 0;
            $bScore = 0;

            if ($banSku !== '') {
                if (str_contains($aL, $banSku)) $aScore += 10;
                if (str_contains($bL, $banSku)) $bScore += 10;
            }
            if ($sku !== '') {
                if (str_contains($aL, $sku)) $aScore += 6;
                if (str_contains($bL, $sku)) $bScore += 6;
            }
            if ($barcode !== '') {
                if (str_contains($aL, $barcode)) $aScore += 6;
                if (str_contains($bL, $barcode)) $bScore += 6;
            }

            // Prefer shorter URLs (often closer to canonical PDP) when tie-breaking.
            if ($aScore === $bScore) {
                return mb_strlen($a) <=> mb_strlen($b);
            }

            return $bScore <=> $aScore;
        });

        return $links;
    }
}


