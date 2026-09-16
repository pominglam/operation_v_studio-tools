<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Support\Products\ModelKitSeriesCatalog;
use App\Support\Products\Storefront\StorefrontTag;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gunpla kit series lookup via gunpla.fandom.com (kit pages use |franchise=, not MS "appearing in").
 */
final class GundamFandomSeriesLookupService
{
    private const BASE_URL = 'https://gunpla.fandom.com/api.php';

    private const TIMEOUT_SECONDS = 15;

    /** @var array<string, array{fandom_series: ?string, wiki_title: ?string, erp_series: ?string, tag_slug: ?string}|null> */
    private array $memoryCache = [];

    /**
     * @return array{fandom_series: ?string, wiki_title: ?string, erp_series: ?string, tag_slug: ?string, evidence: string}|null
     */
    public function lookup(string $productName, ?string $cacheKey = null): ?array
    {
        $cacheKey ??= mb_strtolower(trim($productName));
        if ($cacheKey === '') {
            return null;
        }

        if (array_key_exists($cacheKey, $this->memoryCache)) {
            $cached = $this->memoryCache[$cacheKey];

            return $cached === null ? null : array_merge($cached, ['evidence' => 'gunpla:cache']);
        }

        $diskCache = $this->readDiskCache($cacheKey);
        if ($diskCache !== null) {
            $this->memoryCache[$cacheKey] = $diskCache;

            return array_merge($diskCache, ['evidence' => 'gunpla:cache']);
        }

        $query = $this->buildSearchQuery($productName);
        if ($query === '') {
            $this->memoryCache[$cacheKey] = null;

            return null;
        }

        usleep(150_000);

        $gradePrefix = $this->extractGradePrefix($productName);
        $wikiTitle = $this->searchWikiTitle($query, $productName, $gradePrefix);
        if ($wikiTitle === null) {
            $this->writeDiskCache($cacheKey, null);
            $this->memoryCache[$cacheKey] = null;

            return null;
        }

        $erpSeries = $this->fetchSeriesFromWikiTitle($wikiTitle);
        if ($erpSeries === null) {
            $this->writeDiskCache($cacheKey, null);
            $this->memoryCache[$cacheKey] = null;

            return null;
        }

        $tagSlug = StorefrontTag::slugify($erpSeries);

        $result = [
            'fandom_series' => $erpSeries,
            'wiki_title' => $wikiTitle,
            'erp_series' => $erpSeries,
            'tag_slug' => $tagSlug,
        ];
        $this->writeDiskCache($cacheKey, $result);
        $this->memoryCache[$cacheKey] = $result;

        return array_merge($result, ['evidence' => 'gunpla:'.$wikiTitle]);
    }

    /**
     * Disk/memory cache only — no HTTP (safe for taxonomy UI batch loads).
     *
     * @return array{fandom_series: ?string, wiki_title: ?string, erp_series: ?string, tag_slug: ?string}|null
     */
    public function lookupCachedOnly(string $cacheKey): ?array
    {
        if (array_key_exists($cacheKey, $this->memoryCache)) {
            return $this->memoryCache[$cacheKey];
        }

        $diskCache = $this->readDiskCache($cacheKey);

        if ($diskCache !== null) {
            $this->memoryCache[$cacheKey] = $diskCache;
        }

        return $diskCache;
    }

    public function wikiPageUrl(string $wikiTitle): string
    {
        return 'https://gunpla.fandom.com/wiki/'.rawurlencode(str_replace(' ', '_', $wikiTitle));
    }

    public function buildSearchQuery(string $productName): string
    {
        $name = trim($productName);
        $name = preg_replace('/^(?:High Grade|Master Grade|Real Grade|Entry Grade|Perfect Grade|SD Gundam|EX-Standard|Orphans HG|HGUC|HGCE|HGAC|HGAW|HGBF|HGBD:?R?|HGIBO|HG|MG|RG|PG|SD|RE|SDBF|HGBC)\s*/iu', '', $name) ?? $name;
        $name = preg_replace('/^\d+\/\d+\s*/', '', $name) ?? $name;
        $name = preg_replace('/^#\d+\s*/', '', $name) ?? $name;
        $name = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $name) ?? $name;
        $name = preg_replace('/\s*\([^\)]*\)\s*/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return $name;
    }

    public function extractGradePrefix(string $productName): ?string
    {
        if (preg_match('/^(HGUC|HGCE|HGAC|HGAW|HGGBM|HGGBB|HGBF|HGBD:?R?|HGIBO|HGBC|SDBF|ENTRY GRADE|EX-STANDARD|HGFA|MGEX|MGSD|MG|RG|PG|RE|SD|HG)\b/iu', trim($productName), $match) === 1) {
            return mb_strtoupper($match[1]);
        }

        return null;
    }

    private function searchWikiTitle(string $query, string $originalName, ?string $gradePrefix): ?string
    {
        $response = $this->get([
            'action' => 'query',
            'list' => 'search',
            'srsearch' => $query,
            'srlimit' => 10,
            'format' => 'json',
        ]);

        if ($response === null) {
            return null;
        }

        /** @var list<array{title?: string}> $results */
        $results = $response['query']['search'] ?? [];
        if ($results === []) {
            return null;
        }

        $needles = $this->significantTokens($originalName);
        $bestTitle = null;
        $bestScore = -1;

        foreach ($results as $row) {
            $title = $row['title'] ?? null;
            if (! is_string($title) || $title === '') {
                continue;
            }

            $titleUpper = mb_strtoupper($title);
            if (in_array($titleUpper, ['MASTER GRADE', 'HIGH GRADE', 'REAL GRADE', 'PERFECT GRADE'], true)) {
                continue;
            }

            $score = $this->titleMatchScore($title, $needles);

            $titleGrade = $this->extractGradePrefix($title);
            if ($gradePrefix !== null && $titleGrade === $gradePrefix) {
                $score += 10;
            } elseif ($titleGrade !== null) {
                $score += 4;
            } else {
                $score -= 3;
            }

            $distinctNeedles = array_filter($needles, static fn (string $token): bool => mb_strlen($token) >= 5);
            if ($distinctNeedles !== [] && $this->titleContainsAllTokens($title, $distinctNeedles)) {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTitle = $title;
            }
        }

        return $bestScore >= 2 ? $bestTitle : ($results[0]['title'] ?? null);
    }

    /**
     * @param  list<string>  $needles
     */
    /**
     * @param  list<string>  $needles
     */
    private function titleContainsAllTokens(string $title, array $needles): bool
    {
        $hay = mb_strtolower($title);
        foreach ($needles as $needle) {
            if (! str_contains($hay, mb_strtolower($needle))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $needles
     */
    private function titleMatchScore(string $title, array $needles): int
    {
        $hay = mb_strtolower($title);
        $score = 0;
        foreach ($needles as $needle) {
            if (str_contains($hay, mb_strtolower($needle))) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $text): array
    {
        $upper = mb_strtoupper($text);
        preg_match_all('/[A-Z0-9]{3,}/', $upper, $matches);
        $tokens = $matches[0] ?? [];
        $stop = [
            'GUNDAM', 'HIGH', 'GRADE', 'MASTER', 'REAL', 'ENTRY', 'WITH', 'TYPE', 'VER', 'MODEL', 'CUSTOM',
            'HG', 'MG', 'RG', 'PG', 'SD', 'RE', '144', '100', '60', 'HGBF', 'HGUC', 'HGCE', 'HGAC', 'HGAW',
            'HGIBO', 'HGBC', 'SDBF', 'HGBD', 'HGGBM', 'HGGBB', 'HGI', 'BO',
        ];

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! in_array($token, $stop, true),
        ));
    }

    private function fetchSeriesFromWikiTitle(string $wikiTitle): ?string
    {
        $response = $this->get([
            'action' => 'query',
            'prop' => 'revisions',
            'titles' => $wikiTitle,
            'rvslots' => 'main',
            'rvprop' => 'content',
            'format' => 'json',
        ]);

        if ($response === null) {
            return null;
        }

        $pages = $response['query']['pages'] ?? [];
        foreach ($pages as $page) {
            $wikitext = $page['revisions'][0]['slots']['main']['*'] ?? null;
            if (! is_string($wikitext)) {
                continue;
            }

            $series = $this->extractPrimarySeries($wikitext);

            return $series !== null && $series !== '' ? $series : null;
        }

        return null;
    }

    private function extractPrimarySeries(string $wikitext): ?string
    {
        if (preg_match('/\|franchise\s*=\s*([^\n|}]+)/i', $wikitext, $franchiseMatch) === 1) {
            $candidate = $this->normalizeFandomSeriesValue($franchiseMatch[1]);
            if ($this->isPlausibleSeriesName($candidate)) {
                return ModelKitSeriesCatalog::erpSeriesForFandomName($candidate);
            }
        }

        if (preg_match('/\|series\s*=\s*([^\n|}]+)/i', $wikitext, $match) === 1) {
            $candidate = $this->normalizeFandomSeriesValue($match[1]);
            if ($this->isPlausibleSeriesName($candidate)) {
                return ModelKitSeriesCatalog::erpSeriesForFandomName($candidate);
            }
        }

        if (preg_match('/\|\s*series\s*=\s*\{\{[^|}]+\|([^|}\n]+)/i', $wikitext, $templateMatch) === 1) {
            $candidate = $this->normalizeFandomSeriesValue($templateMatch[1]);
            if ($this->isPlausibleSeriesName($candidate)) {
                return ModelKitSeriesCatalog::erpSeriesForFandomName($candidate);
            }
        }

        if (preg_match('/appearing in \'\'\[\[([^|\]]+)/i', $wikitext, $appearMatch) === 1) {
            $candidate = $this->normalizeFandomSeriesValue($appearMatch[1]);
            if ($this->isPlausibleSeriesName($candidate)) {
                return ModelKitSeriesCatalog::erpSeriesForFandomName($candidate);
            }
        }

        return null;
    }

    private function normalizeFandomSeriesValue(string $raw): string
    {
        $value = trim($raw);
        $value = preg_replace('/\[\[([^|\]]+)(?:\|[^\]]+)?\]\]/', '$1', $value) ?? $value;
        $value = preg_replace('/\{\{[^}]+\}\}/', '', $value) ?? $value;

        if (str_contains($value, ';')) {
            $value = trim(explode(';', $value)[0]);
        }

        if (str_contains($value, ',')) {
            $value = trim(explode(',', $value)[0]);
        }

        if (str_contains($value, '~')) {
            $value = trim(explode('~', $value)[0]);
        }

        return trim($value);
    }

    private function isPlausibleSeriesName(string $series): bool
    {
        if ($series === '' || mb_strlen($series) > 64) {
            return false;
        }

        $rejectNeedles = [
            'Dynasty Warriors', 'SD Gundam G Generation', 'Mobile Suit Gundam:',
            'Gundam Evolve', 'Gundam Versus', 'Model Suit Gunpla Builders',
            'Mobile Suit Variation', 'Gundam EXA', 'Gundam MS Graphica',
            'Plamo-Kyoshiro', 'Mobile Suit Gundam in UC', 'Gunpla Builders',
        ];
        foreach ($rejectNeedles as $needle) {
            if (str_contains($series, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string|int>  $params
     * @return array<string, mixed>|null
     */
    private function get(array $params): ?array
    {
        $url = self::BASE_URL;
        $started = microtime(true);

        try {
            $response = retry(2, function () use ($url, $params) {
                return Http::timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(5)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'OperationV-PricingTool/1.0 (series-audit)'])
                    ->get($url, $params)
                    ->throw();
            }, 500);
        } catch (ConnectionException|\Throwable $e) {
            Log::channel('external_api')->error('gunpla_fandom_lookup_failed', [
                'url' => $url,
                'params' => $params,
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return null;
        }

        Log::channel('external_api')->info('gunpla_fandom_lookup_ok', [
            'url' => $url,
            'params' => $params,
            'status' => $response->status(),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * @return array{fandom_series: ?string, wiki_title: ?string, erp_series: ?string, tag_slug: ?string}|null
     */
    private function readDiskCache(string $cacheKey): ?array
    {
        $path = $this->cachePath();
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        /** @var array<string, mixed> $all */
        $all = json_decode($raw, true);
        if (! is_array($all) || ! array_key_exists($cacheKey, $all)) {
            return null;
        }

        $entry = $all[$cacheKey];
        if ($entry === null) {
            return null;
        }

        if (! is_array($entry)) {
            return null;
        }

        return [
            'fandom_series' => is_string($entry['fandom_series'] ?? null) ? $entry['fandom_series'] : null,
            'wiki_title' => is_string($entry['wiki_title'] ?? null) ? $entry['wiki_title'] : null,
            'erp_series' => is_string($entry['erp_series'] ?? null) ? $entry['erp_series'] : null,
            'tag_slug' => is_string($entry['tag_slug'] ?? null) ? $entry['tag_slug'] : null,
        ];
    }

    /**
     * @param  array{fandom_series: ?string, wiki_title: ?string, erp_series: ?string, tag_slug: ?string}|null  $value
     */
    private function writeDiskCache(string $cacheKey, ?array $value): void
    {
        $path = $this->cachePath();
        /** @var array<string, mixed> $all */
        $all = [];
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $all = $decoded;
                }
            }
        }

        $all[$cacheKey] = $value;
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function cachePath(): string
    {
        return storage_path('app/model-kit-series-gunpla-fandom-cache.json');
    }
}
