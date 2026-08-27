<?php

declare(strict_types=1);

namespace App\Services\PriceResearch\Providers;

use App\DAL\Products\ProductExternalContentRepository;
use App\Models\Product;
use App\Services\PriceResearch\DTOs\PriceLookupResult;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\PriceResearch\Support\HtmlPriceParser;
use Illuminate\Support\Arr;
use Throwable;

abstract class AbstractSearchProvider implements CompetitorPriceProvider
{
    protected function extractTitleForMatching(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1) {
            return html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5);
        }

        if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $html, $m) === 1) {
            $t = trim(strip_tags((string) $m[1]));
            if ($t !== '') {
                return $t;
            }
        }

        if (preg_match('/<h1\\b[^>]*>(.*?)<\\/h1>/is', $html, $m) === 1) {
            $t = trim(strip_tags((string) $m[1]));
            if ($t !== '') {
                return $t;
            }
        }

        return null;
    }

    public function __construct(
        protected readonly ExternalHtmlClient $http,
        protected readonly HtmlPriceParser $parser,
        protected readonly ProductExternalContentRepository $contents,
    ) {}

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

        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));
        if ($desc === '') {
            return null;
        }

        return mb_substr($desc, 0, 64);
    }

    protected function normalizeProductDescriptionForSearch(string $description): string
    {
        $description = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $description) ?? $description;
        $description = preg_replace('/,\\s*Mobile Suit Gundam[^,]*$/i', '', $description) ?? $description;
        $description = trim(preg_replace('/\\s+/', ' ', $description) ?? $description);

        return $description;
    }

    protected function hasDistinctiveNameTokenMatch(string $description, string $candidateHaystack): bool
    {
        $descLower = mb_strtolower($description);
        $candidateLower = mb_strtolower($candidateHaystack);

        $stop = ['gundam', 'bandai', 'hobby', 'model', 'kit', 'master', 'grade', 'mobile', 'suit', 'ver', 'ka'];
        $shortAllow = ['rg', 'hg', 'mg', 'pg', 'sd', 'eg'];

        $rawTokens = preg_split('/[^a-z0-9]+/i', $descLower) ?: [];
        $distinctive = [];
        foreach ($rawTokens as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, $stop, true) || in_array($token, $shortAllow, true)) {
                continue;
            }
            if (preg_match('/^\d{1,4}$/', $token) === 1) {
                continue;
            }
            if (mb_strlen($token) >= 4) {
                $distinctive[$token] = true;
            }
        }

        if ($distinctive === []) {
            return true;
        }

        foreach (array_keys($distinctive) as $token) {
            if (str_contains($candidateLower, $token)) {
                return true;
            }
        }

        return false;
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
            $desc = $this->normalizeProductDescriptionForSearch($desc);
            $desc = mb_substr($desc, 0, 96);
            if ($desc !== '' && ! in_array($desc, $terms, true)) {
                $terms[] = $desc;
            }
        }

        foreach ($this->supplementalSearchTermsForProduct($product) as $term) {
            if (! in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /**
     * @return array<int, string>
     */
    protected function supplementalSearchTermsForProduct(Product $product): array
    {
        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));
        if ($desc === '') {
            return [];
        }

        $lower = mb_strtolower($desc);
        $terms = [];

        if (preg_match('/ms[\-\s]?06s/i', $desc) === 1
            && str_contains($lower, 'char')
            && str_contains($lower, 'zaku')
            && ! str_contains($lower, 'red comet')
            && ! str_contains($lower, 'redcomet')) {
            $terms[] = 'origin ms-06s red comet zaku';
        }

        if (preg_match('/red[\-\s]?comet/i', $desc) === 1) {
            $terms[] = 'origin ms-06s red comet zaku';
        }

        return $terms;
    }

    protected function sanitizeProductUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $url;
        }

        return $parts['scheme'].'://'.$parts['host'].$parts['path'];
    }

    /**
     * @return array<int, string>
     */
    protected function conflictingVariantTerms(): array
    {
        return [
            'mariner',
            'cannon',
            'johnny ridden',
            'johnny',
            'ridden',
            'matsunaga',
            'high mobility',
            'convert',
            'ewac',
            'gquuuuuux',
            'police zaku',
            'zaku amazing',
            'decal',
            'etching',
            'photo-etch',
            'photo etch',
            'si-gu-mi',
            'wa-gu-mi',
            'head collection',
            'anime color',
        ];
    }

    protected function candidateHasConflictingVariant(string $candidateText, string $description): bool
    {
        $candidate = mb_strtolower($candidateText);
        $desc = mb_strtolower($description);

        foreach ($this->conflictingVariantTerms() as $term) {
            if (str_contains($candidate, $term) && ! str_contains($desc, $term)) {
                return true;
            }
        }

        return false;
    }

    protected function extractPrimaryMobileSuitCode(string $text): ?string
    {
        $norm = $this->normalizeForVariantMatch($text);
        if (preg_match('/ms(\d{2}[a-z]?)/', $norm, $match) !== 1) {
            return null;
        }

        return 'ms'.strtolower((string) $match[1]);
    }

    protected function candidateMobileSuitCodeConsistent(string $candidateText, string $description): bool
    {
        $descCode = $this->extractPrimaryMobileSuitCode($description);
        if ($descCode === null) {
            return true;
        }

        $candidateNorm = $this->normalizeForVariantMatch($candidateText);
        if (! str_contains($candidateNorm, $descCode)) {
            return false;
        }

        if (preg_match_all('/ms(\d{2}[a-z]?)/', $candidateNorm, $matches) === false) {
            return true;
        }

        $descNorm = $this->normalizeForVariantMatch($description);
        foreach ($matches[1] as $rawCode) {
            $code = 'ms'.strtolower((string) $rawCode);
            if ($code !== $descCode && ! str_contains($descNorm, $code)) {
                return false;
            }
        }

        return true;
    }

    protected function candidateTextFromShopifySuggestProduct(array $product): string
    {
        $url = (string) ($product['url'] ?? '');
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $url;

        return (string) (($product['title'] ?? '').' '.$path.' '.($product['type'] ?? ''));
    }

    protected function lookupViaShopifySuggestJson(Product $product): ?PriceLookupResult
    {
        $base = rtrim($this->baseUrl(), '/');

        try {
            $terms = $this->searchTermsForProduct($product);
            if ($terms === []) {
                return null;
            }

            $bestMatch = null;
            $bestScore = PHP_INT_MIN;

            foreach (array_slice($terms, 0, 5) as $term) {
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

                usort($products, function (array $a, array $b) use ($product): int {
                    $aText = $this->candidateTextFromShopifySuggestProduct($a)
                        .' '.(string) ($a['body'] ?? '');
                    $bText = $this->candidateTextFromShopifySuggestProduct($b)
                        .' '.(string) ($b['body'] ?? '');

                    return $this->scoreProductCandidateText($bText, $product) <=> $this->scoreProductCandidateText($aText, $product);
                });

                foreach (array_slice($products, 0, $this->maxCandidateProductUrlsToCheck()) as $p) {
                    $relUrl = (string) ($p['url'] ?? '');
                    if ($relUrl === '') {
                        continue;
                    }

                    $candidateText = $this->candidateTextFromShopifySuggestProduct($p);
                    if ($this->shouldRequireOriginRedCometCandidate($product)
                        && ! str_contains($this->normalizeForVariantMatch($candidateText), 'redcomet')) {
                        continue;
                    }

                    $candidateScore = $this->scoreProductCandidateText(
                        $candidateText.' '.(string) ($p['body'] ?? ''),
                        $product,
                    );
                    if ($candidateScore < 0) {
                        continue;
                    }

                    $productUrl = str_starts_with($relUrl, 'http') ? $relUrl : $base.$relUrl;
                    $productUrl = $this->sanitizeProductUrl($productUrl);

                    $productRes = $this->http->get($productUrl, [], $this->siteKey());
                    if (! $productRes->successful()) {
                        continue;
                    }

                    if (! $this->htmlLikelyMatchesProduct($productRes->body(), $product)) {
                        continue;
                    }

                    $this->captureCompetitorDescription($product, $productUrl, $productRes->body());

                    $offer = $this->parser->extractPriceAndAvailabilityFromHtml($productRes->body());
                    if ($offer['price'] === null) {
                        continue;
                    }

                    if ($candidateScore > $bestScore) {
                        $bestScore = $candidateScore;
                        $bestMatch = PriceLookupResult::found(
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

            return $bestMatch;
        } catch (Throwable) {
            return null;
        }
    }

    protected function productDescriptionWantsAccessory(Product $product): bool
    {
        $desc = mb_strtolower(trim((string) ($product->description ?? '')));

        return preg_match('/\b(decals?|stickers?|waterslides?|photo[\-\s]?etch(?:ed)?|detail[\-\s]?up)\b/i', $desc) === 1;
    }

    protected function textLooksLikeAccessory(string $text): bool
    {
        return preg_match('/\b(decals?|stickers?|waterslides?|photo[\-\s]?etch(?:ed)?|detail[\-\s]?up|g[\-\s]?rework|upgrade parts|add-on|add on)\b/i', $text) === 1;
    }

    protected function textLooksLikeGiftCard(string $text): bool
    {
        return str_contains(mb_strtolower($text), 'gift card');
    }

    protected function normalizeForVariantMatch(string $text): string
    {
        $normalized = mb_strtolower($text);

        return preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? $normalized;
    }

    /**
     * @return array<int, string>
     */
    protected function requiredVariantKeysFromDescription(string $description): array
    {
        $norm = $this->normalizeForVariantMatch($description);
        $keys = [];

        if (str_contains($norm, 'cpacks') || str_contains($norm, 'cpack')) {
            $keys[] = 'cpack';
        }
        if (str_contains($norm, 'bpacks') || str_contains($norm, 'bpack')) {
            $keys[] = 'bpack';
        }
        if (str_contains($norm, 'apacks') || str_contains($norm, 'apack')) {
            $keys[] = 'apack';
        }
        if (str_contains($norm, 'redcomet') || str_contains($norm, 'redcometver')) {
            $keys[] = 'redcomet';
        }

        return $keys;
    }

    /**
     * @param  array<int, string>  $requiredKeys
     */
    protected function candidateSatisfiesVariantKeys(string $candidateText, array $requiredKeys): bool
    {
        if ($requiredKeys === []) {
            return true;
        }

        $norm = $this->normalizeForVariantMatch($candidateText);
        foreach ($requiredKeys as $key) {
            if (! str_contains($norm, $key)) {
                return false;
            }
        }

        if (in_array('cpack', $requiredKeys, true) && str_contains($norm, 'bpack') && ! str_contains($norm, 'cpack')) {
            return false;
        }

        if (in_array('bpack', $requiredKeys, true) && str_contains($norm, 'cpack') && ! str_contains($norm, 'bpack')) {
            return false;
        }

        if (in_array('redcomet', $requiredKeys, true) && ! str_contains($norm, 'redcomet')) {
            return false;
        }

        return true;
    }

    protected function candidateGradeScaleConsistent(string $candidateText, string $description): bool
    {
        $candidate = mb_strtolower($candidateText);
        $query = mb_strtolower($description);

        $wantsMg = preg_match('/\bmg\b/i', $query) === 1 || str_contains($query, 'master grade');
        $wantsHg = preg_match('/\bhg\b/i', $query) === 1 || str_contains($query, 'high grade');
        $wants100 = str_contains($query, '1/100') || preg_match('/\b100\b/', $query) === 1;
        $wants144 = str_contains($query, '1/144') || preg_match('/\b144\b/', $query) === 1;

        $candidateIsMg = preg_match('/\bmg\b/i', $candidate) === 1 || str_contains($candidate, 'master grade');
        $candidateIsHg = preg_match('/\bhg\b/i', $candidate) === 1
            || preg_match('/\bhguc\b/i', $candidate) === 1
            || str_contains($candidate, 'high grade');
        $candidateIs100 = str_contains($candidate, '1/100') || preg_match('/\b100\b/', $candidate) === 1;
        $candidateIs144 = str_contains($candidate, '1/144') || preg_match('/\b144\b/', $candidate) === 1;

        if ($wantsMg && $candidateIsHg && ! $candidateIsMg) {
            return false;
        }

        if ($wantsHg && $candidateIsMg && ! $candidateIsHg) {
            return false;
        }

        if ($wants100 && $candidateIs144 && ! $candidateIs100) {
            return false;
        }

        if ($wants144 && $candidateIs100 && ! $candidateIs144) {
            return false;
        }

        return true;
    }

    protected function shouldRequireOriginRedCometCandidate(Product $product): bool
    {
        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));
        if ($desc === '') {
            return false;
        }

        $lower = mb_strtolower($desc);

        return preg_match('/ms[\-\s]?06s/i', $desc) === 1
            && str_contains($lower, 'char')
            && str_contains($lower, 'zaku')
            && ! preg_match('/#\s*\d+/', $desc)
            && ! str_contains($lower, 'red comet')
            && ! str_contains($this->normalizeForVariantMatch($desc), 'redcomet');
    }

    public function scoreProductCandidateText(string $candidateText, Product $product): int
    {
        $desc = trim((string) ($product->description ?? ''));
        if ($desc === '') {
            return 0;
        }

        $candidate = mb_strtolower($candidateText);
        $score = 0;

        if (! $this->productDescriptionWantsAccessory($product)) {
            if ($this->textLooksLikeAccessory($candidate)) {
                $score -= 40;
            }
            if ($this->textLooksLikeGiftCard($candidate)) {
                $score -= 50;
            }
            if (str_contains($candidate, 'expansion set') && ! str_contains(mb_strtolower($desc), 'expansion')) {
                $score -= 20;
            }
        }

        if (! $this->candidateGradeScaleConsistent($candidate, $desc)) {
            $score -= 30;
        }

        if (! $this->candidateSatisfiesVariantKeys($candidate, $this->requiredVariantKeysFromDescription($desc))) {
            $score -= 35;
        }

        if ($this->candidateHasConflictingVariant($candidate, $desc)) {
            $score -= 50;
        }

        if (! $this->candidateMobileSuitCodeConsistent($candidate, $desc)) {
            $score -= 45;
        }

        if (str_contains($this->normalizeForVariantMatch($desc), 'redcomet')
            && str_contains($this->normalizeForVariantMatch($candidate), 'redcomet')) {
            $score += 10;
        }

        $sku = mb_strtolower(trim((string) ($product->sku ?? '')));
        if ($sku !== '' && str_contains($candidate, $sku)) {
            $score += 12;
        }

        $barcode = mb_strtolower(trim((string) ($product->barcode ?? '')));
        if ($barcode !== '' && str_contains($candidate, $barcode)) {
            $score += 12;
        }

        $rawTokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
        foreach ($rawTokens as $token) {
            $token = mb_strtolower(trim($token));
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            if (in_array($token, ['gundam', 'bandai', 'hobby', 'model', 'kit', 'grade'], true)) {
                continue;
            }
            if (str_contains($candidate, $token)) {
                $score += mb_strlen($token) >= 5 ? 4 : 2;
            }
        }

        if (str_contains($candidate, 'ver ka') || str_contains($candidate, 'ver.ka')) {
            if (str_contains(mb_strtolower($desc), 'ver')) {
                $score += 6;
            }
        }

        if (preg_match('/ms[\-\s]?06s/i', $desc) === 1
            && str_contains(mb_strtolower($desc), 'char')
            && str_contains(mb_strtolower($desc), 'zaku')) {
            $candidateNorm = $this->normalizeForVariantMatch($candidate);
            if (str_contains($candidateNorm, 'redcomet')) {
                $score += 18;
            } elseif (! preg_match('/#\s*\d+/', $desc)) {
                $score -= 12;
            }
        }

        return $score;
    }

    protected function htmlLikelyMatchesProduct(string $html, Product $product): bool
    {
        // Use full HTML for identifier checks, but prefer title-scoped matching for tokens to avoid false
        // positives from recommendation widgets / embedded JSON on PDP pages.
        $fullHaystack = mb_strtolower($html);
        $title = $this->extractTitleForMatching($html);
        $haystack = mb_strtolower($title ?? $html);

        // Prefer strong identifiers when present, but do NOT require them; some competitor PDPs do not
        // expose UPC/SKU in the HTML (e.g. Canadian Gundam). We'll fall back to a stricter token match.
        if (($product->barcode ?? '') !== '' && str_contains($fullHaystack, mb_strtolower((string) $product->barcode))) {
            return true;
        }

        if (($product->sku ?? '') !== '' && str_contains($fullHaystack, mb_strtolower((string) $product->sku))) {
            return true;
        }

        $desc = trim((string) ($product->description ?? ''));
        if ($desc === '') {
            return false;
        }

        if (! $this->productDescriptionWantsAccessory($product)) {
            if ($this->textLooksLikeAccessory($haystack) || $this->textLooksLikeGiftCard($haystack)) {
                return false;
            }
        }

        if (! $this->candidateGradeScaleConsistent($haystack, $desc)) {
            return false;
        }

        if (! $this->candidateSatisfiesVariantKeys($haystack, $this->requiredVariantKeysFromDescription($desc))) {
            return false;
        }

        if ($this->candidateHasConflictingVariant($haystack, $desc)) {
            return false;
        }

        if (! $this->candidateMobileSuitCodeConsistent($haystack, $desc)) {
            return false;
        }

        // Match on meaningful tokens from the description (best-effort).
        // We intentionally allow small wording differences (missing 1–2 tokens) to avoid false "not found"
        // when competitor product titles vary slightly.
        $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
        $rawTokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
        $rawTokens = array_map(static fn (string $t): string => mb_strtolower($t), $rawTokens);

        // Drop common, low-signal tokens (reduces false positives and avoids requiring words like "gundam"),
        // but keep a small allow-list of short, meaningful tokens (grades/scales) so products like
        // "RG 1/144 GOD GUNDAM" can still match a PDP title.
        $stop = ['gundam', 'bandai', 'hobby', 'model', 'kit'];
        $shortAllow = ['rg', 'hg', 'mg', 'pg', 'sd', 'eg'];

        $tokens = array_values(array_filter($rawTokens, static function (string $t) use ($stop, $shortAllow): bool {
            $t = trim($t);
            if ($t === '') {
                return false;
            }

            // Always keep known grade abbreviations even though they're short.
            if (in_array($t, $shortAllow, true)) {
                return true;
            }

            // Keep common scale tokens (e.g. 144, 100) even though they are short.
            if (preg_match('/^\d{3,4}$/', $t) === 1) {
                return true;
            }

            if (in_array($t, $stop, true)) {
                return false;
            }

            return mb_strlen($t) >= 4;
        }));

        // If we ended up with too few tokens (common for short names like "RG ... GOD ..."),
        // do a second pass that allows 3-letter non-stop words (e.g. "god") so matching still works.
        if (count($tokens) < 2) {
            $tokens = array_values(array_filter($rawTokens, static function (string $t) use ($stop, $shortAllow): bool {
                if ($t === '' || in_array($t, $stop, true)) {
                    return false;
                }

                if (in_array($t, $shortAllow, true)) {
                    return true;
                }

                if (preg_match('/^\d{3,4}$/', $t) === 1) {
                    return true;
                }

                return mb_strlen($t) >= 3;
            }));
        }

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

        $requiredTokens = array_values(array_filter($rawTokens, static function (string $t) use ($stop, $shortAllow): bool {
            // Require at least one "name" token match (not just grade/scale), otherwise pages like
            // "RG 1/144 decals" can match "RG 1/144 God Gundam".
            if ($t === '' || in_array($t, $stop, true) || in_array($t, $shortAllow, true)) {
                return false;
            }

            if (preg_match('/^\d{3,4}$/', $t) === 1) {
                return false;
            }

            return mb_strlen($t) >= 3;
        }));

        if ($requiredTokens !== []) {
            $matched = false;
            foreach (array_slice($requiredTokens, 0, 8) as $t) {
                if (str_contains($haystack, $t)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        if ($hits < $minHits) {
            return false;
        }

        return true;
    }

    public function lookup(Product $product): PriceLookupResult
    {
        $terms = $this->searchTermsForProduct($product);
        if ($terms === []) {
            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        }

        try {
            $bestMatch = null;
            $bestScore = PHP_INT_MIN;

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

                        $title = $this->extractTitleForMatching($productRes->body()) ?? '';
                        $candidateScore = $this->scoreProductCandidateText($productUrl.' '.$title, $product);
                        if ($this->shouldRequireOriginRedCometCandidate($product)
                            && ! str_contains($this->normalizeForVariantMatch($productUrl.' '.$title), 'redcomet')) {
                            continue;
                        }
                        if ($candidateScore < 0) {
                            continue;
                        }

                        $this->captureCompetitorDescription($product, $productUrl, $productRes->body());

                        $offer = $this->parser->extractPriceAndAvailabilityFromHtml($productRes->body());
                        if ($offer['price'] === null) {
                            continue;
                        }

                        if ($candidateScore > $bestScore) {
                            $bestScore = $candidateScore;
                            $bestMatch = PriceLookupResult::found(
                                $this->siteKey(),
                                $this->siteName(),
                                $offer['price'],
                                $offer['original_price'],
                                'CAD',
                                $this->sanitizeProductUrl($productUrl),
                                $offer['availability'],
                            );
                        }
                    }
                }
            }

            if ($bestMatch instanceof PriceLookupResult) {
                return $bestMatch;
            }

            return PriceLookupResult::notFound($this->siteKey(), $this->siteName());
        } catch (Throwable $e) {
            return PriceLookupResult::error($this->siteKey(), $this->siteName(), $e->getMessage());
        }
    }

    protected function captureCompetitorDescription(Product $product, string $productUrl, string $html): void
    {
        if ($product->id === null) {
            return;
        }

        $desc = $this->parser->extractDescriptionHtmlFromHtml($html);
        if ($desc === null || trim($desc) === '') {
            return;
        }

        $title = $this->extractTitleForMatching($html);
        $this->contents->upsertForProduct(
            (int) $product->id,
            $this->siteKey(),
            $title,
            $desc,
            null,
            $productUrl,
        );
    }

    protected function maxCandidateProductUrlsToCheck(): int
    {
        return 2;
    }

    /**
     * Prefer candidate URLs that contain the product identifiers (sku/barcode), to reduce false positives
     * when search pages return many close matches.
     *
     * @param  array<int, string>  $links
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
                if (str_contains($aL, $banSku)) {
                    $aScore += 10;
                }
                if (str_contains($bL, $banSku)) {
                    $bScore += 10;
                }
            }
            if ($sku !== '') {
                if (str_contains($aL, $sku)) {
                    $aScore += 6;
                }
                if (str_contains($bL, $sku)) {
                    $bScore += 6;
                }
            }
            if ($barcode !== '') {
                if (str_contains($aL, $barcode)) {
                    $aScore += 6;
                }
                if (str_contains($bL, $barcode)) {
                    $bScore += 6;
                }
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
