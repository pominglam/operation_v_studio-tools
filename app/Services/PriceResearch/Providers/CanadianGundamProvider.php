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
     * @param  array<int, string>  $links
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

            $desc = trim((string) ($product->description ?? ''));
            $desc = preg_replace('/\\s*\\(edited\\)\\s*/i', ' ', $desc) ?? $desc;
            $descLower = mb_strtolower($desc);

            if (($product->barcode ?? '') !== '' && str_contains($titleLower, mb_strtolower((string) $product->barcode))) {
                // Even if the barcode matches, do not accept a different grade/scale (HG vs MG, 1/144 vs 1/100).
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

            // Strong guard: avoid mismatching HG/RG/MG/etc across grades.
            if ($this->gradeOrScaleMismatch($descLower, $titleLower)) {
                return false;
            }

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
        // Normalize a few common grade prefixes into broad groups.
        // HG group includes many HG variants (HGBF/HGUC/HGCE/HGAC/HGBD/etc).
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
