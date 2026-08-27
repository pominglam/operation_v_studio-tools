<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DTOs\CustomOrders\CustomAsiaOrderProductNameSuggestionDTO;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

final class CustomAsiaOrderProductNameSuggestService
{
    public function __construct(
        private readonly ExternalHtmlClient $http,
    ) {}

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    public function suggest(string $query, int $limit = 8): array
    {
        $term = $this->normalizeSearchTerm($query);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $limit = max(1, min($limit, 12));

        $suggestions = $this->fetchAllSourcesParallel($term, $limit);

        return $this->dedupeAndLimit($suggestions, $limit);
    }

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    private function fetchAllSourcesParallel(string $term, int $limit): array
    {
        $q = rawurlencode($term);
        $ghUrl = "https://server.gundamhangar.com/api/products?limit=16&page=1&category=gundam-mobile-suit-kit&outofstock=1&search={$q}";

        $hsBase = rtrim((string) config('price_research.sites.hobby_sense.base_url', 'https://hobbysense.ca'), '/');
        $argamaBase = rtrim((string) config('price_research.sites.argama_hobby.base_url', 'https://argamahobby.com'), '/');
        $shopifyQuery = "q={$q}&resources[type]=product&resources[limit]=12&resources[options][unavailable_products]=show";
        $hsUrl = "{$hsBase}/search/suggest.json?{$shopifyQuery}";
        $argamaUrl = "{$argamaBase}/search/suggest.json?{$shopifyQuery}";

        try {
            $responses = $this->http->poolGetForSuggest([
                'gundam_hangar' => [
                    'url' => $ghUrl,
                    'site_key' => 'gundam_hangar',
                ],
                'hobby_sense' => [
                    'url' => $hsUrl,
                    'site_key' => 'hobby_sense',
                ],
                'argama_hobby' => [
                    'url' => $argamaUrl,
                    'site_key' => 'argama_hobby',
                ],
            ]);
        } catch (Throwable) {
            return $this->fetchAllSourcesSequential($term, $limit);
        }

        return [
            ...$this->parseGundamHangarResponse($responses['gundam_hangar'] ?? null, $term, $limit),
            ...$this->parseShopifySuggestResponse(
                $responses['hobby_sense'] ?? null,
                siteKey: 'hobby_sense',
                siteName: (string) config('price_research.sites.hobby_sense.name', 'Hobby Sense'),
                baseUrl: $hsBase,
                term: $term,
                limit: $limit,
            ),
            ...$this->parseShopifySuggestResponse(
                $responses['argama_hobby'] ?? null,
                siteKey: 'argama_hobby',
                siteName: (string) config('price_research.sites.argama_hobby.name', 'Argama Hobby'),
                baseUrl: $argamaBase,
                term: $term,
                limit: $limit,
            ),
        ];
    }

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    private function fetchAllSourcesSequential(string $term, int $limit): array
    {
        return [
            ...$this->fetchGundamHangarSuggestions($term, $limit),
            ...$this->fetchShopifySuggestions(
                siteKey: 'hobby_sense',
                siteName: (string) config('price_research.sites.hobby_sense.name', 'Hobby Sense'),
                baseUrl: (string) config('price_research.sites.hobby_sense.base_url', 'https://hobbysense.ca'),
                term: $term,
                limit: $limit,
            ),
            ...$this->fetchShopifySuggestions(
                siteKey: 'argama_hobby',
                siteName: (string) config('price_research.sites.argama_hobby.name', 'Argama Hobby'),
                baseUrl: (string) config('price_research.sites.argama_hobby.base_url', 'https://argamahobby.com'),
                term: $term,
                limit: $limit,
            ),
        ];
    }

    private function normalizeSearchTerm(string $query): string
    {
        $term = trim($query);
        $term = preg_replace('/[^a-z0-9\\s]+/i', ' ', $term) ?? $term;
        $term = trim(preg_replace('/\\s+/', ' ', $term) ?? $term);

        return mb_substr($term, 0, 72);
    }

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    public function fetchGundamHangarSuggestions(string $term, int $limit): array
    {
        $q = rawurlencode($term);
        $url = "https://server.gundamhangar.com/api/products?limit=16&page=1&category=gundam-mobile-suit-kit&outofstock=1&search={$q}";

        try {
            $res = $this->http->getForSuggest($url, [
                'Accept' => 'application/json, text/plain, */*',
            ], 'gundam_hangar');

            return $this->parseGundamHangarResponse($res, $term, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    private function parseGundamHangarResponse(?Response $res, string $term, int $limit): array
    {
        if ($res === null || ! $res->successful()) {
            return [];
        }

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($res->body(), true);
            if (! is_array($decoded)) {
                return [];
            }

            /** @var array<int, mixed> $items */
            $items = Arr::get($decoded, 'data', []);
            if (! is_array($items) || $items === []) {
                return [];
            }

            $needleTokens = $this->tokenize($term);
            $scored = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $score = $this->overlapScore($needleTokens, $this->tokenize($title));
                if ($score < 0.15) {
                    continue;
                }

                $slug = (string) ($item['slug'] ?? '');
                $baseUrl = rtrim(config('price_research.sites.gundam_hangar.base_url', 'https://gundamhangar.com'), '/');
                $productUrl = $slug !== '' ? "{$baseUrl}/canadian-gundam-store/product/{$slug}" : null;

                $final = is_numeric($item['final_price'] ?? null) ? (float) $item['final_price'] : null;
                $list = is_numeric($item['price'] ?? null) ? (float) $item['price'] : null;
                $price = ($final !== null && $final > 0) ? $final : $list;

                $stock = is_numeric($item['stock'] ?? null) ? (int) $item['stock'] : null;
                $availability = $stock === null ? null : ($stock > 0 ? 'in_stock' : 'sold_out');

                $scored[] = [
                    'score' => $score,
                    'dto' => new CustomAsiaOrderProductNameSuggestionDTO(
                        title: $title,
                        priceCad: $price !== null ? number_format($price, 2, '.', '') : null,
                        sourceKey: 'gundam_hangar',
                        sourceName: config('price_research.sites.gundam_hangar.name', 'Gundam Hangar'),
                        productUrl: $productUrl,
                        availability: $availability,
                    ),
                ];
            }

            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            return array_map(
                static fn (array $row): CustomAsiaOrderProductNameSuggestionDTO => $row['dto'],
                array_slice($scored, 0, $limit),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    public function fetchShopifySuggestions(
        string $siteKey,
        string $siteName,
        string $baseUrl,
        string $term,
        int $limit,
    ): array {
        if ($limit <= 0) {
            return [];
        }

        $base = rtrim($baseUrl, '/');
        $q = rawurlencode($term);
        $suggestUrl = "{$base}/search/suggest.json?q={$q}&resources[type]=product&resources[limit]=12&resources[options][unavailable_products]=show";

        try {
            $res = $this->http->getForSuggest($suggestUrl, [
                'Accept' => 'application/json, text/plain, */*',
            ], $siteKey);

            return $this->parseShopifySuggestResponse($res, $siteKey, $siteName, $base, $term, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    private function parseShopifySuggestResponse(
        ?Response $res,
        string $siteKey,
        string $siteName,
        string $baseUrl,
        string $term,
        int $limit,
    ): array {
        if ($limit <= 0 || $res === null || ! $res->successful()) {
            return [];
        }

        try {
            /** @var array<string, mixed>|null $json */
            $json = $res->json();
            if (! is_array($json)) {
                return [];
            }

            /** @var array<int, array<string, mixed>> $products */
            $products = Arr::get($json, 'resources.results.products', []);
            if (! is_array($products) || $products === []) {
                return [];
            }

            $base = rtrim($baseUrl, '/');
            $needleTokens = $this->tokenize($term);
            $scored = [];

            foreach (array_slice($products, 0, 12) as $product) {
                $title = trim((string) ($product['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $score = $this->overlapScore($needleTokens, $this->tokenize($title));
                if ($score < 0.1) {
                    continue;
                }

                $relUrl = (string) ($product['url'] ?? '');
                $productUrl = $relUrl !== '' && str_starts_with($relUrl, '/')
                    ? $base.$relUrl
                    : ($relUrl !== '' ? $relUrl : null);

                $priceStr = (string) ($product['price'] ?? '');
                $priceCad = is_numeric($priceStr) ? number_format((float) $priceStr, 2, '.', '') : null;

                $availability = null;
                if (array_key_exists('available', $product)) {
                    $availability = ((bool) $product['available']) ? 'in_stock' : 'sold_out';
                }

                $scored[] = [
                    'score' => $score,
                    'dto' => new CustomAsiaOrderProductNameSuggestionDTO(
                        title: $title,
                        priceCad: $priceCad,
                        sourceKey: $siteKey,
                        sourceName: $siteName,
                        productUrl: $productUrl,
                        availability: $availability,
                    ),
                ];
            }

            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            return array_map(
                static fn (array $row): CustomAsiaOrderProductNameSuggestionDTO => $row['dto'],
                array_slice($scored, 0, $limit),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, CustomAsiaOrderProductNameSuggestionDTO>  $suggestions
     * @return array<int, CustomAsiaOrderProductNameSuggestionDTO>
     */
    private function dedupeAndLimit(array $suggestions, int $limit): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = mb_strtolower(trim($suggestion->title));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $suggestion;

            if (count($unique) >= $limit) {
                break;
            }
        }

        return $unique;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^a-z0-9\\s]+/i', ' ', $text) ?? $text;
        $parts = preg_split('/\\s+/', $text) ?: [];

        $stop = ['the', 'and', 'for', 'with', 'ver', 'version', 'gundam', 'bandai', 'hobby', 'model', 'kit'];
        $shortAllow = ['rg', 'hg', 'mg', 'pg', 'sd', 'eg'];

        return array_values(array_filter($parts, static function (string $token) use ($stop, $shortAllow): bool {
            if ($token === '' || in_array($token, $stop, true)) {
                return false;
            }

            if (in_array($token, $shortAllow, true)) {
                return true;
            }

            if (preg_match('/^\d{3,4}$/', $token) === 1) {
                return true;
            }

            return mb_strlen($token) >= 3;
        }));
    }

    /**
     * @param  array<int, string>  $needleTokens
     * @param  array<int, string>  $haystackTokens
     */
    private function overlapScore(array $needleTokens, array $haystackTokens): float
    {
        if ($needleTokens === [] || $haystackTokens === []) {
            return 0.0;
        }

        $common = array_intersect($needleTokens, $haystackTokens);

        return count($common) / max(1, count($needleTokens));
    }
}
