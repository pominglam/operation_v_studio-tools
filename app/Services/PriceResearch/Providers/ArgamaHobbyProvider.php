<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use Illuminate\Support\Arr;
use Throwable;

final class ArgamaHobbyProvider extends AbstractSearchProvider
{
    public function siteKey(): string
    {
        return 'argama_hobby';
    }

    public function siteName(): string
    {
        return config('price_research.sites.argama_hobby.name', 'Argama Hobby');
    }

    protected function baseUrl(): string
    {
        return config('price_research.sites.argama_hobby.base_url', 'https://argamahobby.com');
    }

    protected function maxCandidateProductUrlsToCheck(): int
    {
        // Argama search pages often return many close matches. Check a few more PDP candidates before giving up.
        return 6;
    }

    public function lookup(Product $product): PriceLookupResult
    {
        // Argama appears to be Shopify. Use predictive search JSON to avoid heavy HTML search pages and
        // reduce the number of PDP fetches, improving speed without sacrificing match quality.
        $base = rtrim($this->baseUrl(), '/');

        try {
            $terms = $this->searchTermsForProduct($product);
            if ($terms === []) {
                return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
            }

            foreach (array_slice($terms, 0, 4) as $term) {
                $q = rawurlencode($term);
                $suggestUrl = "{$base}/search/suggest.json?q={$q}&resources[type]=product&resources[limit]=12&resources[options][unavailable_products]=show";

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

                $sku = mb_strtolower(trim((string) ($product->sku ?? '')));
                $barcode = mb_strtolower(trim((string) ($product->barcode ?? '')));

                usort($products, function (array $a, array $b) use ($sku, $barcode): int {
                    $aText = mb_strtolower((string) (($a['title'] ?? '').' '.($a['body'] ?? '').' '.($a['url'] ?? '')));
                    $bText = mb_strtolower((string) (($b['title'] ?? '').' '.($b['body'] ?? '').' '.($b['url'] ?? '')));

                    $aScore = 0;
                    $bScore = 0;
                    if ($sku !== '') {
                        if (str_contains($aText, $sku)) {
                            $aScore += 6;
                        }
                        if (str_contains($bText, $sku)) {
                            $bScore += 6;
                        }
                    }
                    if ($barcode !== '') {
                        if (str_contains($aText, $barcode)) {
                            $aScore += 6;
                        }
                        if (str_contains($bText, $barcode)) {
                            $bScore += 6;
                        }
                    }

                    // Prefer actual kits over decals if tie.
                    if (str_contains($aText, 'decal') || str_contains($aText, 'sticker')) {
                        $aScore -= 2;
                    }
                    if (str_contains($bText, 'decal') || str_contains($bText, 'sticker')) {
                        $bScore -= 2;
                    }

                    return $bScore <=> $aScore;
                });

                foreach (array_slice($products, 0, 5) as $p) {
                    $relUrl = (string) ($p['url'] ?? '');
                    if ($relUrl === '') {
                        continue;
                    }

                    $productUrl = str_starts_with($relUrl, 'http') ? $relUrl : $base.$relUrl;

                    $productRes = $this->http->get($productUrl, [], $this->siteKey());
                    if (! $productRes->successful()) {
                        continue;
                    }

                    if (! $this->htmlLikelyMatchesProduct($productRes->body(), $product)) {
                        continue;
                    }

                    $this->captureCompetitorDescription($product, $productUrl, $productRes->body());

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
        } catch (Throwable) {
            // Fall through to the generic HTML search flow below.
        }

        // Fallback to the generic (HTML) flow if predictive search fails.
        return parent::lookup($product);
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    protected function orderCandidateProductUrls(Product $product, array $links): array
    {
        $desc = mb_strtolower(trim((string) ($product->description ?? '')));
        $tokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(static fn (string $t): string => mb_strtolower($t), $tokens))));

        $wantGod = in_array('god', $tokens, true);
        $wantGundam = in_array('gundam', $tokens, true);
        $wantRg = in_array('rg', $tokens, true);
        $want144 = in_array('144', $tokens, true);

        usort($links, function (string $a, string $b) use ($wantGod, $wantGundam, $wantRg, $want144): int {
            $aL = mb_strtolower($a);
            $bL = mb_strtolower($b);

            $score = static function (string $u) use ($wantGod, $wantGundam, $wantRg, $want144): int {
                $s = 0;
                if (str_contains($u, 'gift-card')) {
                    $s -= 50;
                }
                if (str_contains($u, 'decal') || str_contains($u, 'sticker')) {
                    $s -= 8;
                }

                if ($wantGod && str_contains($u, 'god')) {
                    $s += 12;
                }
                if ($wantGundam && str_contains($u, 'gundam')) {
                    $s += 8;
                }
                if ($wantRg && str_contains($u, 'rg')) {
                    $s += 3;
                }
                if ($want144 && str_contains($u, '144')) {
                    $s += 3;
                }

                // Prefer shorter URLs when tie-breaking.
                $s -= (int) floor(mb_strlen($u) / 80);

                return $s;
            };

            $aScore = $score($aL);
            $bScore = $score($bL);

            if ($aScore === $bScore) {
                return mb_strlen($a) <=> mb_strlen($b);
            }

            return $bScore <=> $aScore;
        });

        return $links;
    }
}
