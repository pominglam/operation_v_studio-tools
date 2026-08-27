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
        return 8;
    }

    /**
     * @param  array<int, string>  $links
     * @return array<int, string>
     */
    protected function orderCandidateProductUrls(Product $product, array $links): array
    {
        usort($links, function (string $a, string $b) use ($product): int {
            return $this->scoreProductCandidateText($b, $product) <=> $this->scoreProductCandidateText($a, $product);
        });

        return $links;
    }

    protected function searchTermForProduct(Product $product): ?string
    {
        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));
        if ($desc !== '') {
            $nameFocused = $this->nameFocusedSearchTerm($desc);
            if ($nameFocused !== '') {
                return $nameFocused;
            }

            $compact = $this->compactSearchTerm($desc);
            if ($compact !== '') {
                return $compact;
            }

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

    /**
     * @return array<int, string>
     */
    protected function searchTermsForProduct(Product $product): array
    {
        $terms = [];
        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));

        if ($desc !== '') {
            $nameFocused = $this->nameFocusedSearchTerm($desc);
            if ($nameFocused !== '') {
                $terms[] = $nameFocused;
            }

            $compact = $this->compactSearchTerm($desc);
            if ($compact !== '' && ! in_array($compact, $terms, true)) {
                $terms[] = $compact;
            }

            $full = mb_substr($desc, 0, 80);
            if ($full !== '' && ! in_array($full, $terms, true)) {
                $terms[] = $full;
            }
        }

        $sku = trim((string) ($product->sku ?? ''));
        if ($sku !== '' && ! in_array($sku, $terms, true)) {
            $terms[] = $sku;
        }

        $barcode = trim((string) ($product->barcode ?? ''));
        if ($barcode !== '' && ! in_array($barcode, $terms, true)) {
            $terms[] = $barcode;
        }

        return $terms;
    }

    private function compactSearchTerm(string $description): string
    {
        $compact = preg_replace('/\\([^)]*\\)/', ' ', $description) ?? $description;
        $compact = preg_replace(
            '/\\b(master\\s+grade|high\\s+grade|real\\s+grade|perfect\\s+grade)\\b/i',
            ' ',
            $compact,
        ) ?? $compact;
        $compact = preg_replace('/\\b1\\s*\\/\\s*\\d+\\b/', ' ', $compact) ?? $compact;
        $compact = preg_replace('/\\b(mg|hg|rg|pg|sd|eg)\\b/i', ' ', $compact) ?? $compact;
        $compact = trim(preg_replace('/\\s+/', ' ', $compact) ?? $compact);

        return mb_substr($compact, 0, 80);
    }

    private function nameFocusedSearchTerm(string $description): string
    {
        $lower = mb_strtolower($description);
        if (! str_contains($lower, 'narrative') || ! str_contains($lower, 'gundam')) {
            return '';
        }

        $grade = 'MG';
        if (preg_match('/\\b(hg|rg|pg|sd|eg|mg)\\b/i', $description, $match) === 1) {
            $grade = strtoupper((string) $match[1]);
        } elseif (str_contains($lower, 'master grade')) {
            $grade = 'MG';
        } elseif (str_contains($lower, 'high grade')) {
            $grade = 'HG';
        }

        $term = $grade.' Narrative Gundam';
        if (preg_match('/c[\-\s]?packs/i', $description) === 1) {
            $term .= ' C-Packs';
        }
        if (preg_match('/ver\.?\s*ka/i', $description) === 1) {
            $term .= ' Ver.Ka';
        }

        return $term;
    }

    protected function htmlLikelyMatchesProduct(string $html, Product $product): bool
    {
        // CanadianGundam pages contain lots of global nav text that can trigger generic token matching.
        // Scope matching to the product title to avoid false positives (e.g. Pokemon pages).
        $title = $this->extractProductTitle($html);
        if ($title === null) {
            return parent::htmlLikelyMatchesProduct($html, $product);
        }

        $titleLower = mb_strtolower($title);
        $desc = $this->normalizeProductDescriptionForSearch((string) ($product->description ?? ''));
        if ($desc === '') {
            return false;
        }

        $descLower = mb_strtolower($desc);

        if (! $this->productDescriptionWantsAccessory($product)) {
            if ($this->textLooksLikeAccessory($titleLower) || $this->textLooksLikeGiftCard($titleLower)) {
                return false;
            }
        }

        if (($product->barcode ?? '') !== '' && str_contains($titleLower, mb_strtolower((string) $product->barcode))) {
            if ($this->gradeOrScaleMismatch($descLower, $titleLower)) {
                return false;
            }

            return true;
        }

        if (($product->sku ?? '') !== '' && str_contains($titleLower, mb_strtolower((string) $product->sku))) {
            if ($this->gradeOrScaleMismatch($descLower, $titleLower)) {
                return false;
            }

            return true;
        }

        if ($this->gradeOrScaleMismatch($descLower, $titleLower)) {
            return false;
        }

        if (! $this->candidateGradeScaleConsistent($titleLower, $descLower)) {
            return false;
        }

        if (! $this->candidateSatisfiesVariantKeys($titleLower, $this->requiredVariantKeysFromDescription($desc))) {
            return false;
        }

        if (! $this->hasDistinctiveNameTokenMatch($descLower, $titleLower)) {
            return false;
        }

        if (str_contains($descLower, 'gundam') && ! str_contains($titleLower, 'gundam')) {
            return false;
        }

        $tokens = preg_split('/[^a-z0-9]+/i', $desc) ?: [];
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            static fn (string $t): bool => mb_strlen($t) >= 4,
        )));

        $hits = 0;
        foreach (array_slice($tokens, 0, 8) as $token) {
            if (str_contains($titleLower, mb_strtolower($token))) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    private function gradeOrScaleMismatch(string $expectedTextLower, string $actualTextLower): bool
    {
        $expectedGrade = $this->extractGradeGroup($expectedTextLower);
        $actualGrade = $this->extractGradeGroup($actualTextLower);
        if ($expectedGrade !== null && $actualGrade !== null && $expectedGrade !== $actualGrade) {
            return true;
        }

        $expectedScale = $this->extractScale($expectedTextLower);
        $actualScale = $this->extractScale($actualTextLower);
        if ($expectedScale !== null && $actualScale !== null && $expectedScale !== $actualScale) {
            return true;
        }

        return false;
    }

    private function extractGradeGroup(string $textLower): ?string
    {
        if (preg_match('/\\b(mg|master\\s+grade)\\b/', $textLower) === 1) {
            return 'mg';
        }
        if (preg_match('/\\b(rg|real\\s+grade)\\b/', $textLower) === 1) {
            return 'rg';
        }
        if (preg_match('/\\b(pg|perfect\\s+grade)\\b/', $textLower) === 1) {
            return 'pg';
        }
        if (preg_match('/\\b(hg|hguc|hgce|hgac|hgbf|hgbd|hgbd:r|hggto|hgtb|hg\\s*1\\s*\\/\\s*144|high\\s+grade)\\b/', $textLower) === 1) {
            return 'hg';
        }
        if (preg_match('/\\b(sd|sdw|sdcs|ex-std|ex\\s*standard|cross\\s+silhouette)\\b/', $textLower) === 1) {
            return 'sd';
        }

        return null;
    }

    private function extractScale(string $textLower): ?string
    {
        if (preg_match('/\\b1\\s*\\/\\s*(\\d{2,3})\\b/', $textLower, $m) === 1) {
            $den = (string) ($m[1] ?? '');

            return $den !== '' ? $den : null;
        }

        return null;
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
            "{$base}/search?controller=search&orderby=position&orderway=desc&search_query={$q}",
        ];
    }
}
