<?php

declare(strict_types=1);

namespace App\Services\Products\GundamHangar;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\ProductPdpSearchTermsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GundamHangarContentSyncService
{
    public const string SOURCE = 'gundamhangar';
    public const string API_BASE_URL = 'https://server.gundamhangar.com/api';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly GundamHangarApiParser $parser,
        private readonly ProductPdpSearchTermsService $terms,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    /**
     * @param  callable(string):void|null  $trace
     */
    public function syncForProduct(Product $product, ?string $syncUuid = null, ?callable $trace = null): void
    {
        $sku = is_string($product->sku) ? trim($product->sku) : '';
        $name = is_string($product->description) ? trim($product->description) : '';
        $baseCtx = [
            'sync_uuid' => $syncUuid,
            'product_uuid' => is_string($product->uuid ?? null) ? (string) $product->uuid : null,
            'product_id' => (int) ($product->id ?? 0),
            'sku' => $sku !== '' ? $sku : null,
            'source' => self::SOURCE,
        ];

        $terms = $this->terms->termsForProduct($product);
        $this->trace($trace, 'start', ['terms_count' => count($terms)]);
        if ($terms === []) {
            $this->clearExternal($product);
            $this->trace($trace, 'summary', ['result' => 'no_terms']);
            return;
        }

        $picked = $this->resolveBestCandidate($terms, $name !== '' ? $name : $sku, $trace);
        if ($picked === null) {
            $this->clearExternal($product);
            $this->trace($trace, 'summary', ['result' => 'pdp_not_found']);
            return;
        }

        $pdpUrl = $this->productSimilarUrl($picked['slug']);
        $productUrl = $this->productUrl($picked['slug']);
        $this->trace($trace, 'pdp_found', [
            'slug' => $picked['slug'],
            'pdp' => $pdpUrl,
            'product' => $productUrl,
            'title' => $picked['title'],
        ]);

        // The PDP flow is AJAX-based. Hit this endpoint for parity/visibility in logs.
        try {
            $pdpRes = $this->http->get($pdpUrl, siteKey: self::SOURCE);
            $this->trace($trace, 'pdp_ajax', [
                'pdp' => $pdpUrl,
                'http' => (string) $pdpRes->status(),
            ]);
        } catch (Throwable $e) {
            // PDP endpoint can be flaky; we can still persist from search payload.
            $this->trace($trace, 'pdp_ajax_exception', [
                'pdp' => $pdpUrl,
                'error' => $e->getMessage(),
            ]);
        }

        $productDetail = null;
        try {
            $productRes = $this->http->get($productUrl, siteKey: self::SOURCE);
            if ($productRes->successful()) {
                $productDetail = $this->parser->extractProductDetailFromJson((string) $productRes->body());
            }
            $this->trace($trace, 'product_api', [
                'product' => $productUrl,
                'http' => (string) $productRes->status(),
                'has_detail' => is_array($productDetail) ? 'true' : 'false',
            ]);
        } catch (Throwable $e) {
            $this->trace($trace, 'product_api_exception', [
                'product' => $productUrl,
                'error' => $e->getMessage(),
            ]);
        }

        $imageUrls = is_array($productDetail) && ($productDetail['image_urls'] ?? []) !== []
            ? (array) $productDetail['image_urls']
            : $this->buildImageUrlsFromCandidate($picked);
        $this->trace($trace, 'images_extracted', ['count' => count($imageUrls)]);

        $assetRows = $imageUrls !== []
            ? $this->downloadImageAssets($product, $imageUrls, $pdpUrl, $trace)
            : [];
        $this->assets->replaceForProduct((int) $product->id, self::SOURCE, $assetRows);

        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: self::SOURCE,
            title: is_array($productDetail) && is_string($productDetail['title'] ?? null) ? $productDetail['title'] : $picked['title'],
            descriptionHtml: is_array($productDetail) ? ($productDetail['description_html'] ?? $picked['description_html']) : $picked['description_html'],
            attributes: is_array($productDetail)
                ? (($productDetail['attributes'] ?? []) !== [] ? (array) $productDetail['attributes'] : null)
                : ($picked['attributes'] !== [] ? $picked['attributes'] : null),
            sourceUrl: $productUrl,
        );

        Log::info('gundamhangar.sync.completed', [
            ...$baseCtx,
            'pdp_url' => $pdpUrl,
            'images_downloaded' => count($assetRows),
        ]);
        $this->trace($trace, 'summary', [
            'result' => 'ok',
            'pdp' => $pdpUrl,
            'images_extracted' => count($imageUrls),
            'images_downloaded' => count($assetRows),
        ]);
    }

    /**
     * @param  array<int, string>  $terms
     * @return array{
     *   title:string,
     *   slug:string,
     *   description_html:?string,
     *   featured_image:?string,
     *   image_number:int,
     *   attributes:array<string,string>
     * }|null
     */
    private function resolveBestCandidate(array $terms, string $matchName, ?callable $trace): ?array
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($terms as $term) {
            $q = trim((string) $term);
            if ($q === '') continue;

            $searchUrl = $this->searchUrlForQuery($q);
            $this->trace($trace, 'search_try', ['q' => $q, 'url' => $searchUrl]);
            try {
                $res = $this->http->get($searchUrl, siteKey: self::SOURCE);
            } catch (Throwable $e) {
                // Some queries can trigger upstream redirect loops; skip term and continue.
                $this->trace($trace, 'search_exception', [
                    'q' => $q,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if (! $res->successful()) {
                $this->trace($trace, 'search_http_failed', [
                    'q' => $q,
                    'http' => (string) $res->status(),
                ]);
                continue;
            }

            $cands = $this->parser->extractSearchCandidatesFromJson((string) $res->body());
            if ($cands === []) {
                $this->trace($trace, 'search_no_candidates', ['q' => $q]);
                continue;
            }

            $picked = $this->parser->pickBestCandidate($cands, $q);
            if ($picked === null) {
                $this->trace($trace, 'search_pick_failed', ['q' => $q]);
                continue;
            }

            $score = $this->titleScore($picked['title'], $matchName);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $picked;
            }
            $this->trace($trace, 'search_picked', [
                'q' => $q,
                'cands' => count($cands),
                'slug' => $picked['slug'],
                'score' => number_format($score, 3, '.', ''),
            ]);

            if ($score >= 0.85) {
                return $best;
            }
        }

        return $best;
    }

    private function searchUrlForQuery(string $query): string
    {
        $q = trim($query);
        $q = mb_strtolower($q);
        // GH search endpoint behaves more reliably with encoded literal search tokens.
        $search = rawurlencode($q);
        $qs = http_build_query([
            'limit' => 10,
            'outofstock' => '',
            'page' => 1,
            'search' => $search,
        ]);
        return self::API_BASE_URL.'/products?'.$qs;
    }

    private function productSimilarUrl(string $slug): string
    {
        return self::API_BASE_URL.'/product-similar/'.rawurlencode(trim($slug));
    }

    private function productUrl(string $slug): string
    {
        return self::API_BASE_URL.'/product/'.rawurlencode(trim($slug));
    }

    /**
     * @param  array{
     *   title:string,
     *   slug:string,
     *   description_html:?string,
     *   featured_image:?string,
     *   image_number:int,
     *   attributes:array<string,string>
     * }  $candidate
     * @return array<int, string>
     */
    private function buildImageUrlsFromCandidate(array $candidate): array
    {
        $featured = trim((string) ($candidate['featured_image'] ?? ''));
        if ($featured === '') return [];

        $count = max(1, min((int) ($candidate['image_number'] ?? 1), 30));
        $path = parse_url($featured, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return [$featured];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $ext = is_string($ext) && $ext !== '' ? $ext : 'jpg';

        $root = preg_replace('/\/\d+\.[a-zA-Z0-9]+$/', '', $featured);
        if (! is_string($root) || trim($root) === '') {
            return [$featured];
        }

        $urls = [];
        for ($i = 0; $i < $count; $i++) {
            $urls[] = "{$root}/{$i}.{$ext}";
        }
        return array_values(array_unique($urls));
    }

    private function titleScore(string $title, string $target): float
    {
        $a = $this->tokens($title);
        $b = $this->tokens($target);
        if ($a === [] || $b === []) return 0.0;
        $set = array_fill_keys($a, true);
        $hits = 0;
        foreach ($b as $t) {
            if (isset($set[$t])) $hits++;
        }
        return $hits / max(1, count($b));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $s): array
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') return [];
        $s = preg_replace('/\b\d+\s*\/\s*\d+\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if ($s === '') return [];
        return array_values(array_unique(array_filter(explode(' ', $s), static fn (string $t): bool => $t !== '')));
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, origin_url?: string|null, checksum_sha256?: string|null}>
     */
    private function downloadImageAssets(Product $product, array $imageUrls, string $refererUrl, ?callable $trace): array
    {
        $disk = Storage::disk('local');
        $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) ($product->sku ?? '')) ?: 'unknown';

        $rows = [];
        $index = 0;
        $attempted = 0;
        $skippedNon200 = 0;
        $skippedNonImage = 0;
        $skippedEmpty = 0;
        $skippedException = 0;
        foreach ($imageUrls as $url) {
            $url = trim((string) $url);
            if ($url === '') continue;
            $attempted++;
            $index++;

            try {
                $res = $this->http->get($url, [
                    'Accept' => 'image/*',
                    'Referer' => $refererUrl,
                ], siteKey: self::SOURCE);
            } catch (Throwable $e) {
                $skippedException++;
                $this->trace($trace, 'image_download_exception', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            if (! $res->successful()) {
                $skippedNon200++;
                continue;
            }

            $mime = $res->header('Content-Type');
            $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                $fallbackMime = $this->mimeFromImageUrl($url);
                if ($fallbackMime === null) {
                    $skippedNonImage++;
                    continue;
                }
                $mime = $fallbackMime;
            }

            $body = $res->body();
            if (! is_string($body) || $body === '') {
                $skippedEmpty++;
                continue;
            }

            $ext = $this->extensionForMime($mime) ?? 'jpg';
            $filename = "gundamhangar-{$safeSku}-{$index}.{$ext}";
            $storagePath = "gundamhangar/images/{$safeSku}/{$filename}";
            $disk->put($storagePath, $body);

            $rows[] = [
                'kind' => 'image',
                'storage_path' => $storagePath,
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => strlen($body),
                'origin_url' => $url,
                'checksum_sha256' => hash('sha256', $body),
            ];
        }

        $this->trace($trace, 'images_downloaded', [
            'attempted' => $attempted,
            'downloaded' => count($rows),
            'skipped_non_200' => $skippedNon200,
            'skipped_non_image' => $skippedNonImage,
            'skipped_empty' => $skippedEmpty,
            'skipped_exception' => $skippedException,
        ]);
        return $rows;
    }

    private function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    private function mimeFromImageUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $ext = mb_strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }

    private function clearExternal(Product $product): void
    {
        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: self::SOURCE,
            title: null,
            descriptionHtml: null,
            attributes: null,
            sourceUrl: null,
        );
        $this->assets->replaceForProduct((int) $product->id, self::SOURCE, []);
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
            $s = str_replace(["\r", "\n"], ' ', trim($s));
            if (mb_strlen($s) > 500) $s = mb_substr($s, 0, 500).'…';
            $parts[] = "{$k}={$s}";
        }
        $line = '[gundamhangar]['.$event.']'.($parts !== [] ? ' '.implode(' ', $parts) : '');
        $trace($line);
    }
}

