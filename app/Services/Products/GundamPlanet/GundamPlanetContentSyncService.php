<?php

declare(strict_types=1);

namespace App\Services\Products\GundamPlanet;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\ProductPdpSearchTermsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class GundamPlanetContentSyncService
{
    public const string SOURCE = 'gundamplanet';
    public const string BASE_URL = GundamPlanetHtmlParser::BASE_URL;

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly GundamPlanetHtmlParser $parser,
        private readonly ProductExternalAssetRepository $assets,
        private readonly ProductPdpSearchTermsService $terms,
    ) {}

    /**
     * @param  callable(string):void|null  $trace  Optional trace sink for UI/debug.
     */
    public function syncForProduct(Product $product, ?string $syncUuid = null, ?callable $trace = null): void
    {
        $barcode = is_string($product->barcode) ? trim($product->barcode) : '';
        $sku = is_string($product->sku) ? trim($product->sku) : '';
        $name = is_string($product->description) ? trim($product->description) : '';

        $candidates = $this->terms->termsForProduct($product);

        $baseCtx = [
            'sync_uuid' => is_string($syncUuid) && trim($syncUuid) !== '' ? trim($syncUuid) : null,
            'product_uuid' => is_string($product->uuid ?? null) ? (string) $product->uuid : null,
            'product_id' => (int) ($product->id ?? 0),
            'sku' => $sku !== '' ? $sku : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'name' => $name !== '' ? $name : null,
            'source' => self::SOURCE,
        ];

        Log::info('gundamplanet.sync.start', [
            ...$baseCtx,
            ...$this->summarizeTerms($candidates),
        ]);
        $this->trace($trace, 'start', [
            'terms' => $this->summarizeTermsInline($candidates),
        ]);

        if ($candidates === []) {
            $this->assets->replaceForProduct((int) $product->id, self::SOURCE, []);
            Log::info('gundamplanet.sync.no_terms', $baseCtx);
            $this->trace($trace, 'no_terms', []);
            $this->trace($trace, 'summary', [
                'result' => 'no_terms',
                'images_extracted' => 0,
                'images_downloaded' => 0,
            ]);
            return;
        }

        $targetName = $name !== '' ? $name : ($sku !== '' ? $sku : $barcode);
        $resolved = $this->resolveBestPdp($candidates, $targetName, $baseCtx, $trace);
        if ($resolved === null) {
            // Clear stale images if we can no longer resolve a PDP.
            $this->assets->replaceForProduct((int) $product->id, self::SOURCE, []);
            Log::info('gundamplanet.sync.pdp.not_found', $baseCtx);
            $this->trace($trace, 'pdp_not_found', []);
            $this->trace($trace, 'summary', [
                'result' => 'pdp_not_found',
                'images_extracted' => 0,
                'images_downloaded' => 0,
            ]);
            return;
        }

        Log::info('gundamplanet.sync.pdp.found', [
            ...$baseCtx,
            'pdp_url' => $resolved['url'],
            'picked_term' => $resolved['term'],
            'picked_title' => $resolved['title'],
            'picked_score' => $resolved['score'],
        ]);
        $this->trace($trace, 'pdp_found', [
            'pdp' => $resolved['url'],
            'term' => $resolved['term'],
            'score' => $resolved['score'],
            'title' => $resolved['title'],
        ]);

        $pdpRes = $this->http->get($resolved['url'], siteKey: self::SOURCE);
        if (! $pdpRes->successful()) {
            Log::warning('gundamplanet.sync.pdp.fetch_failed', [
                ...$baseCtx,
                'pdp_url' => $resolved['url'],
                'http_status' => $pdpRes->status(),
            ]);
            $this->trace($trace, 'pdp_fetch_failed', [
                'pdp' => $resolved['url'],
                'http' => $pdpRes->status(),
            ]);
            $this->trace($trace, 'summary', [
                'result' => 'pdp_fetch_failed',
                'http' => $pdpRes->status(),
                'images_extracted' => 0,
                'images_downloaded' => 0,
                'pdp' => $resolved['url'],
            ]);
            return;
        }

        $imageUrls = $this->parser->extractImageUrlsFromPdpHtml((string) $pdpRes->body());
        Log::info('gundamplanet.sync.images.extracted', [
            ...$baseCtx,
            'pdp_url' => $resolved['url'],
            'image_urls_count' => count($imageUrls),
        ]);
        $this->trace($trace, 'images_extracted', [
            'pdp' => $resolved['url'],
            'count' => count($imageUrls),
        ]);

        $assetRows = $imageUrls !== []
            ? $this->downloadImageAssets($product, $imageUrls, $resolved['url'], $baseCtx, $trace)
            : [];

        // Always replace: if the gallery is empty or downloads fail, we still clear stale images.
        $this->assets->replaceForProduct((int) $product->id, self::SOURCE, $assetRows);
        Log::info('gundamplanet.sync.completed', [
            ...$baseCtx,
            'pdp_url' => $resolved['url'],
            'replaced_assets_count' => count($assetRows),
        ]);
        $this->trace($trace, 'completed', [
            'pdp' => $resolved['url'],
            'replaced_assets' => count($assetRows),
        ]);
        $this->trace($trace, 'summary', [
            'result' => 'ok',
            'pdp' => $resolved['url'],
            'picked_term' => $resolved['term'],
            'picked_score' => $resolved['score'],
            'images_extracted' => count($imageUrls),
            'images_downloaded' => count($assetRows),
        ]);
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function resolveBestPdp(array $terms, string $matchName, array $baseCtx, ?callable $trace): ?array
    {
        $matchName = trim($matchName);
        /** @var array{url: string, title: string, term: string, score: float}|null $best */
        $best = null;
        $bestScore = -1;

        foreach ($terms as $term) {
            $q = trim((string) $term);
            if ($q === '') continue;

            $searchUrl = $this->searchUrlForQuery($q);
            Log::info('gundamplanet.sync.search.try', [
                ...$baseCtx,
                'query' => $q,
                'search_url' => $searchUrl,
            ]);
            $this->trace($trace, 'search_try', [
                'q' => $q,
                'url' => $searchUrl,
            ]);

            $res = $this->http->get($searchUrl, siteKey: self::SOURCE);
            if (! $res->successful()) {
                Log::warning('gundamplanet.sync.search.http_failed', [
                    ...$baseCtx,
                    'query' => $q,
                    'search_url' => $searchUrl,
                    'http_status' => $res->status(),
                ]);
                $this->trace($trace, 'search_http_failed', [
                    'q' => $q,
                    'http' => $res->status(),
                ]);
                continue;
            }

            $cands = $this->parser->extractSearchCandidatesFromSearchHtml((string) $res->body());
            if ($cands === []) {
                Log::info('gundamplanet.sync.search.no_candidates', [
                    ...$baseCtx,
                    'query' => $q,
                    'search_url' => $searchUrl,
                ]);
                $this->trace($trace, 'search_no_candidates', [
                    'q' => $q,
                ]);
                continue;
            }

            // Pick best candidate by token overlap against the query term.
            $picked = $this->parser->pickBestCandidate($cands, $q);
            if ($picked === null) {
                Log::info('gundamplanet.sync.search.pick_failed', [
                    ...$baseCtx,
                    'query' => $q,
                    'search_url' => $searchUrl,
                    'candidates_count' => count($cands),
                ]);
                $this->trace($trace, 'search_pick_failed', [
                    'q' => $q,
                    'cands' => count($cands),
                ]);
                continue;
            }

            // Prefer candidates whose title matches the full product name better (cross-term stability).
            $score = $this->titleScore($picked['title'], $matchName);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'url' => $picked['url'],
                    'title' => $picked['title'],
                    'term' => $q,
                    'score' => $score,
                ];
            }

            Log::info('gundamplanet.sync.search.picked', [
                ...$baseCtx,
                'query' => $q,
                'search_url' => $searchUrl,
                'candidates_count' => count($cands),
                'picked_url' => $picked['url'],
                'picked_title' => $picked['title'],
                'picked_score_vs_name' => $score,
                'best_score_so_far' => $bestScore,
            ]);
            $this->trace($trace, 'search_picked', [
                'q' => $q,
                'cands' => count($cands),
                'pdp' => $picked['url'],
                'score' => $score,
                'title' => $picked['title'],
            ]);

            // Early exit for very good matches.
            if ($bestScore >= 0.85) {
                return $best;
            }
        }

        return $best;
    }

    private function searchUrlForQuery(string $query): string
    {
        $q = rawurlencode(trim($query));
        $qs = "q={$q}&options%5Bprefix%5D=last";
        return GundamPlanetHtmlParser::BASE_URL."/search?{$qs}";
    }

    private function titleScore(string $title, string $target): float
    {
        $a = mb_strtolower(trim($title));
        $b = mb_strtolower(trim($target));
        if ($a === '' || $b === '') return 0.0;

        // Normalize whitespace/punctuation for stable comparison.
        $a = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $a) ?? $a;
        $b = preg_replace('/[^\\p{L}\\p{N}\\s]+/u', ' ', $b) ?? $b;
        $a = trim(preg_replace('/\\s+/u', ' ', $a) ?? $a);
        $b = trim(preg_replace('/\\s+/u', ' ', $b) ?? $b);
        if ($a === '' || $b === '') return 0.0;

        // Token overlap (0..1).
        $aTokens = array_values(array_filter(explode(' ', $a)));
        $bTokens = array_values(array_filter(explode(' ', $b)));
        if ($aTokens === [] || $bTokens === []) return 0.0;
        $set = array_fill_keys($aTokens, true);
        $hits = 0;
        foreach ($bTokens as $t) {
            if (isset($set[$t])) $hits++;
        }
        return $hits / max(1, count($bTokens));
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, origin_url?: string|null, checksum_sha256?: string|null}>
     */
    private function downloadImageAssets(Product $product, array $imageUrls, string $pdpUrl, array $baseCtx, ?callable $trace): array
    {
        $disk = Storage::disk('local');
        $safeSku = $this->safeSkuDir((string) $product->sku);

        $rows = [];
        $index = 0;
        $attempted = 0;
        $skippedNon200 = 0;
        $skippedNonImage = 0;
        $skippedEmpty = 0;

        foreach (array_slice($imageUrls, 0, 30) as $url) {
            $url = trim((string) $url);
            if ($url === '') continue;
            $attempted++;
            $index++;

            $res = $this->http->get($url, [
                'Accept' => 'image/*',
                'Referer' => $pdpUrl,
            ], siteKey: self::SOURCE);
            if (! $res->successful()) {
                $skippedNon200++;
                continue;
            }

            $mime = $res->header('Content-Type');
            $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                $skippedNonImage++;
                continue;
            }

            $body = $res->body();
            if (! is_string($body) || $body === '') {
                $skippedEmpty++;
                continue;
            }

            $ext = $this->extensionForMime($mime) ?? $this->extensionFromUrl($url) ?? 'jpg';
            $filename = "gundamplanet-{$safeSku}-{$index}.{$ext}";
            $storagePath = "gundamplanet/images/{$safeSku}/{$filename}";

            $sha = hash('sha256', $body);
            $disk->put($storagePath, $body);

            $rows[] = [
                'kind' => 'image',
                'storage_path' => $storagePath,
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => strlen($body),
                'origin_url' => $url,
                'checksum_sha256' => $sha,
            ];
        }

        Log::info('gundamplanet.sync.images.download_summary', [
            ...$baseCtx,
            'pdp_url' => $pdpUrl,
            'attempted' => $attempted,
            'downloaded' => count($rows),
            'skipped_non_200' => $skippedNon200,
            'skipped_non_image' => $skippedNonImage,
            'skipped_empty_body' => $skippedEmpty,
        ]);
        $this->trace($trace, 'images_downloaded', [
            'attempted' => $attempted,
            'downloaded' => count($rows),
            'skipped_non_200' => $skippedNon200,
            'skipped_non_image' => $skippedNonImage,
            'skipped_empty' => $skippedEmpty,
        ]);

        return $rows;
    }

    private function safeSkuDir(string $sku): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $sku) ?: 'unknown';
    }

    private function extensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : null;
    }

    private function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $terms
     * @return array{terms_count: int, terms_sample: array<int, string>, terms_truncated: bool}
     */
    private function summarizeTerms(array $terms): array
    {
        $terms = array_values(array_unique(array_filter(array_map('strval', $terms), static fn (string $v): bool => trim($v) !== '')));
        $limit = 12;
        return [
            'terms_count' => count($terms),
            'terms_sample' => array_slice($terms, 0, $limit),
            'terms_truncated' => count($terms) > $limit,
        ];
    }

    private function summarizeTermsInline(array $terms): string
    {
        $terms = array_values(array_unique(array_filter(array_map('strval', $terms), static fn (string $v): bool => trim($v) !== '')));
        $limit = 8;
        $sample = array_slice($terms, 0, $limit);
        $tail = count($terms) > $limit ? ' …' : '';
        return implode(' | ', $sample).$tail;
    }

    /**
     * @param  callable(string):void|null  $trace
     * @param  array<string, mixed>  $data
     */
    private function trace(?callable $trace, string $event, array $data): void
    {
        if ($trace === null) return;

        $parts = [];
        foreach ($data as $k => $v) {
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $s = is_string($v) ? $v : (is_numeric($v) ? (string) $v : null);
            if ($s === null || trim($s) === '') continue;
            $parts[] = "{$k}={$s}";
        }

        $line = '[gundamplanet]['.$event.']'.($parts !== [] ? ' '.implode(' ', $parts) : '');
        $trace($line);
    }
}

