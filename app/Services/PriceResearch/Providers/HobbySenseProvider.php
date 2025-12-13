<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use Illuminate\Support\Arr;
use Throwable;

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
            // Strip local annotations like "(edited)" that can hurt search relevance.
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

    /**
     * HobbySense search endpoint:
     * https://hobbysense.ca/search?q=...
     *
     * @return array<int, string>
     */
    protected function searchUrlsForTerm(string $term): array
    {
        $base = rtrim($this->baseUrl(), '/');
        $q = rawurlencode($term);

        return [
            "{$base}/search?q={$q}",
        ];
    }

    public function lookup(Product $product): PriceLookupResult
    {
        // HobbySense uses Shopify and the HTML search page is heavily JS-rendered; the DOM can contain
        // template placeholders like "/products/{{ product.handle }}". Use the predictive search JSON
        // endpoint to reliably obtain the PDP handle.
        $base = rtrim($this->baseUrl(), '/');

        try {
            $terms = $this->searchTermsForProduct($product);
            if ($terms === []) {
                return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
            }

            foreach (array_slice($terms, 0, 4) as $term) {
                $q = rawurlencode($term);
                $suggestUrl = "{$base}/search/suggest.json?q={$q}&resources[type]=product&resources[limit]=10&resources[options][unavailable_products]=show";

                $res = $this->http->get($suggestUrl, [
                    'Accept' => 'application/json, text/plain, */*',
                ], $this->siteKey());
                if (! $res->successful()) {
                    continue;
                }

                /** @var array<string, mixed>|null $json */
                $json = $res->json();
                if (! is_array($json)) {
                    continue;
                }

                /** @var array<int, array<string, mixed>> $products */
                $products = Arr::get($json, 'resources.results.products', []);
                if (! is_array($products) || $products === []) {
                    continue;
                }

                // Prefer results whose title/body includes the SKU/barcode.
                $sku = mb_strtolower((string) ($product->sku ?? ''));
                $barcode = mb_strtolower((string) ($product->barcode ?? ''));

                usort($products, function (array $a, array $b) use ($sku, $barcode): int {
                    $aText = mb_strtolower((string) (($a['title'] ?? '').' '.($a['body'] ?? '')));
                    $bText = mb_strtolower((string) (($b['title'] ?? '').' '.($b['body'] ?? '')));

                    $aScore = 0;
                    $bScore = 0;
                    if ($sku !== '') {
                        if (str_contains($aText, $sku)) $aScore += 4;
                        if (str_contains($bText, $sku)) $bScore += 4;
                    }
                    if ($barcode !== '') {
                        if (str_contains($aText, $barcode)) $aScore += 4;
                        if (str_contains($bText, $barcode)) $bScore += 4;
                    }

                    return $bScore <=> $aScore;
                });

                foreach (array_slice($products, 0, 5) as $p) {
                    $relUrl = (string) ($p['url'] ?? '');
                    if ($relUrl === '' || ! str_starts_with($relUrl, '/products/')) {
                        continue;
                    }

                    $productUrl = $base.$relUrl;

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

                    // As a fallback, use the price from the JSON payload.
                    $priceStr = (string) ($p['price'] ?? '');
                    $price = is_numeric($priceStr) ? (float) $priceStr : null;
                    if ($price !== null) {
                        $avail = null;
                        if (array_key_exists('available', $p)) {
                            $avail = ((bool) $p['available']) ? 'in_stock' : 'sold_out';
                        }

                        return PriceLookupResult::found(
                            $this->siteKey(),
                            $this->siteName(),
                            $price,
                            null,
                            'CAD',
                            $productUrl,
                            $avail,
                        );
                    }
                }
            }

            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        } catch (Throwable $e) {
            return PriceLookupResult::error($this->siteKey(), $this->siteName(), $e->getMessage());
        }
    }
}


