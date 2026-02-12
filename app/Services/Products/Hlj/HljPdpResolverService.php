<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use Illuminate\Support\Str;

final class HljPdpResolverService
{
    public const string SOURCE = 'hlj';
    private const int MAX_PDP_PROBES = 8;
    private const float MIN_TITLE_MATCH_SCORE = 0.45;

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly HljHtmlParser $parser,
    ) {}

    public function resolvePdpUrlForProduct(Product $product): ?string
    {
        $candidates = [];

        $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
        if ($barcode !== '') $candidates[] = $barcode;

        $sku = is_string($product->sku) ? trim($product->sku) : '';
        if ($sku !== '') $candidates[] = $sku;

        $name = is_string($product->description) ? trim($product->description) : '';
        $constraints = $name !== '' ? $this->queryConstraints($name) : null;
        if ($name !== '') {
            foreach ($this->queryVariants($name) as $q) {
                $candidates[] = $q;
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates)));
        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $q) {
            $url = $this->resolveBestPdpUrlForQuery($q, $barcode !== '' ? $barcode : null, $constraints);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function resolvePdpUrlForQuery(string $query): ?string
    {
        return $this->resolveBestPdpUrlForQuery($query, null, $this->queryConstraints($query));
    }

    /**
     * Exposes the exact HLJ query-variant generation logic so other crawlers
     * can reuse the same search term variations (barcode/SKU should be handled by callers).
     *
     * @return array<int, string>
     */
    public function queryVariantsForName(string $name): array
    {
        return $this->queryVariants($name);
    }

    /**
     * @param  array{grade?:string|null}|null  $constraints
     */
    private function resolveBestPdpUrlForQuery(string $query, ?string $expectedJanCode, ?array $constraints): ?string
    {
        $raw = trim($query);
        if ($raw === '') return null;

        $candidates = $this->fetchSearchCandidates($raw);
        if ($candidates === []) {
            return null;
        }

        // If we have a barcode/JAN code, verify by reading PDP details for a few candidates.
        $expected = $expectedJanCode !== null ? trim($expectedJanCode) : null;
        if ($expected !== null && $expected !== '') {
            foreach (array_slice($candidates, 0, self::MAX_PDP_PROBES) as $pdpUrl) {
                $res = $this->http->get($pdpUrl, siteKey: self::SOURCE);
                if (! $res->successful()) {
                    continue;
                }
                $jan = $this->parser->extractJanCodeFromPdpHtml((string) $res->body());
                if ($jan !== null && $jan === $expected) {
                    return $pdpUrl;
                }
            }
        }

        // If we don't have a JAN code, avoid picking the wrong PDP by scoring candidate titles.
        // Only return a PDP when we have a minimally good title match; otherwise let the caller try other query variants.
        $best = $this->pickBestByTitleMatch($candidates, $raw, $constraints);
        if ($best !== null) {
            return $best;
        }

        // If there's only one candidate, return it (low risk).
        if (count($candidates) === 1) {
            $only = $candidates[0];
            if ($constraints === null) {
                return $only;
            }

            // If we have constraints (e.g. grade), validate the single candidate before returning it.
            $res = $this->http->get($only, siteKey: self::SOURCE);
            if (! $res->successful()) {
                return null;
            }

            $title = $this->parser->extractTitleAndDescription((string) $res->body())['title'] ?? null;
            if (! is_string($title) || trim($title) === '') {
                return null;
            }

            return $this->titleMeetsConstraints($title, $constraints) ? $only : null;
        }

        // Multiple candidates + no JAN + no good title match => return null to avoid a wrong PDP.
        return null;
    }

    /**
     * @return array<int, string>
     */
    private function fetchSearchCandidates(string $query): array
    {
        $q = rawurlencode(trim($query));
        if ($q === '') return [];

        // HLJ search uses "Word" (not "q") for their public search UI.
        $urls = [
            "https://www.hlj.com/search/?Word={$q}",
            // Fallback: older/alternate param used by some pages.
            "https://www.hlj.com/search/?q={$q}",
        ];

        foreach ($urls as $searchUrl) {
            $res = $this->http->get($searchUrl, siteKey: self::SOURCE);
            if (! $res->successful()) {
                continue;
            }
            $found = $this->parser->extractPdpUrlsFromSearchHtml((string) $res->body());
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function queryVariants(string $name): array
    {
        $raw = trim($name);
        if ($raw === '') {
            return [];
        }

        $variants = [$raw];

        $noModelCode = $this->stripModelCode($raw);
        if ($noModelCode !== '' && $noModelCode !== $raw) {
            $variants[] = $noModelCode;
        }

        $human = $this->humanQuery($raw);
        if ($human !== '' && $human !== $raw && $human !== $noModelCode) {
            $variants[] = $human;
        }

        $simplified = $this->simplifyQuery($raw);
        if ($simplified !== '' && $simplified !== $raw) {
            $variants[] = $simplified;
        }

        if ($noModelCode !== '' && $noModelCode !== $raw) {
            $simplifiedNoCode = $this->simplifyQuery($noModelCode);
            if ($simplifiedNoCode !== '' && ! in_array($simplifiedNoCode, $variants, true)) {
                $variants[] = $simplifiedNoCode;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * Strip model codes like "MBF-02VV" / "RX-78-2" that often reduce search recall.
     */
    private function stripModelCode(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }

        // Alnum model codes with hyphens (MBF-02VV, RX-78-2, etc).
        $t = preg_replace('/\\b[A-Z]{2,}-\\d+[A-Z0-9-]*\\b/iu', ' ', $t) ?? $t;
        // Unicode model codes that may contain greek letters (RX-93-ν2, etc).
        $t = preg_replace('/\\b[A-Z]{1,3}-\\d{1,3}-(?:[\\p{L}\\p{N}]{1,6})\\b/iu', ' ', $t) ?? $t;
        $t = preg_replace('/\\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * Create a “human” query: remove grade/scale/model-code fragments while preserving the recognizable name
     * and product-line tokens like Ver.Ka.
     */
    private function humanQuery(string $text): string
    {
        $q = trim($text);
        if ($q === '') {
            return '';
        }

        // Remove scale.
        $q = preg_replace('/\\b\\d+\\s*\\/\\s*\\d+\\b/u', ' ', $q) ?? $q;
        $q = preg_replace('/\\b1\\s*\\/\\s*\\d+\\s*scale\\b/iu', ' ', $q) ?? $q;

        // Remove verbose grade phrases like "Master Grade (MG)" but keep Ver.Ka.
        $q = preg_replace('/\\bMaster\\s+Grade\\s*\\(\\s*MG\\s*\\)\\b/iu', ' ', $q) ?? $q;
        $q = preg_replace('/\\bReal\\s+Grade\\s*\\(\\s*RG\\s*\\)\\b/iu', ' ', $q) ?? $q;
        $q = preg_replace('/\\bHigh\\s+Grade\\s*\\(\\s*HG\\s*\\)\\b/iu', ' ', $q) ?? $q;
        $q = preg_replace('/\\bEntry\\s+Grade\\s*\\(\\s*EG\\s*\\)\\b/iu', ' ', $q) ?? $q;

        // Remove grade tokens.
        $q = preg_replace('/\\b(HGUC|HG|RG|MGSD|MGEX|MG|PG|SD|EG|ENTRY\\s+GRADE)\\b/iu', ' ', $q) ?? $q;

        // Remove model codes (including greek-letter variants).
        $q = $this->stripModelCode($q);

        // Normalize Ver.Ka to a stable token.
        $q = preg_replace('/\\bver\\s*\\.\\s*ka\\b/iu', 'verka', $q) ?? $q;

        // Drop punctuation/noise.
        $q = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $q) ?? $q;
        $q = preg_replace('/\\s+/u', ' ', $q) ?? $q;

        return trim($q);
    }

    private function simplifyQuery(string $name): string
    {
        $q = trim($name);
        if ($q === '') return '';

        // Drop common scale fragments.
        $q = preg_replace('/\\b\\d+\\s*\\/\\s*\\d+\\b/u', ' ', $q) ?? $q; // 1/144 etc
        $q = preg_replace('/\\b1\\s*\\/\\s*\\d+\\s*scale\\b/iu', ' ', $q) ?? $q;

        // Drop common grades/prefixes.
        $q = preg_replace('/\\b(HGUC|HG|RG|MGSD|MGEX|MG|PG|SD|EG|ENTRY\\s+GRADE)\\b/iu', ' ', $q) ?? $q;

        // Drop punctuation/noise, keep words/numbers.
        $q = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $q) ?? $q;

        // Drop model-code fragments that may remain after punctuation stripping (e.g. "MBF 02VV").
        $q = preg_replace('/\\b[A-Z]{2,}\\s*\\d+[A-Z0-9]{1,}\\b/iu', ' ', $q) ?? $q;

        $q = preg_replace('/\\s+/u', ' ', $q) ?? $q;

        return trim($q);
    }

    /**
     * @param  array<int, string>  $candidatePdpUrls
     */
    private function pickBestByTitleMatch(array $candidatePdpUrls, string $query, ?array $constraints): ?string
    {
        // If the query is purely numeric, title matching isn't meaningful.
        if (preg_match('/^\\d+$/', trim($query)) === 1) {
            return null;
        }

        $queryTokens = $this->titleTokens($query);
        if ($queryTokens === []) {
            return null;
        }

        $bestUrl = null;
        $bestScore = 0.0;

        foreach (array_slice($candidatePdpUrls, 0, self::MAX_PDP_PROBES) as $pdpUrl) {
            $res = $this->http->get($pdpUrl, siteKey: self::SOURCE);
            if (! $res->successful()) {
                continue;
            }

            $title = $this->parser->extractTitleAndDescription((string) $res->body())['title'] ?? null;
            if (! is_string($title) || trim($title) === '') {
                continue;
            }
            if (! $this->titleMeetsConstraints($title, $constraints)) {
                continue;
            }

            $score = $this->titleMatchScore($queryTokens, $this->titleTokens($title));
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUrl = $pdpUrl;
            }
        }

        if ($bestUrl === null) {
            return null;
        }

        return $bestScore >= self::MIN_TITLE_MATCH_SCORE ? $bestUrl : null;
    }

    /**
     * @return array<int, string>
     */
    private function titleTokens(string $text): array
    {
        $t = Str::lower($text);
        $t = str_replace(['ver.ka', 'ver ka'], 'verka', $t);
        $t = preg_replace('/\\b\\d+\\s*\\/\\s*\\d+\\b/u', ' ', $t) ?? $t;
        $t = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\\s+/u', ' ', $t) ?? $t;
        $t = trim($t);
        if ($t === '') return [];

        $tokens = explode(' ', $t);
        $tokens = array_values(array_filter($tokens, static fn (string $x): bool => $x !== '' && strlen($x) >= 2));

        // Remove overly-common noise tokens.
        $stop = ['scale', 'with', 'premium', 'decal', 'decals', 'kit', 'model'];
        $tokens = array_values(array_filter($tokens, static function (string $x) use ($stop): bool {
            return ! in_array($x, $stop, true);
        }));

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int, string>  $queryTokens
     * @param  array<int, string>  $titleTokens
     */
    private function titleMatchScore(array $queryTokens, array $titleTokens): float
    {
        if ($queryTokens === [] || $titleTokens === []) return 0.0;

        $titleSet = array_fill_keys($titleTokens, true);
        $hits = 0;
        foreach ($queryTokens as $t) {
            if (isset($titleSet[$t])) {
                $hits++;
            }
        }

        return $hits / max(1, count($queryTokens));
    }

    /**
     * @return array{grade?:string|null}|null
     */
    private function queryConstraints(string $query): ?array
    {
        $q = trim($query);
        if ($q === '') {
            return null;
        }

        // HLJ titles often include grade tokens ("MG", "HGUC", etc). Use that as a safety constraint
        // to avoid selecting unrelated products with similar names.
        if (preg_match('/\\b(HGUC|HG|RG|MGSD|MGEX|MG|PG|SD|EG|ENTRY\\s+GRADE)\\b/iu', $q, $m) === 1) {
            return ['grade' => mb_strtoupper(trim((string) $m[1]))];
        }

        return null;
    }

    /**
     * @param  array{grade?:string|null}|null  $constraints
     */
    private function titleMeetsConstraints(string $title, ?array $constraints): bool
    {
        if ($constraints === null) {
            return true;
        }

        $grade = $constraints['grade'] ?? null;
        if (is_string($grade) && trim($grade) !== '') {
            return preg_match('/\\b'.preg_quote(mb_strtoupper($grade), '/').'\\b/u', mb_strtoupper($title)) === 1;
        }

        return true;
    }
}


