<?php

declare(strict_types=1);

namespace App\Services\Products\Newtype;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\ProductPdpSearchTermsService;
use App\Support\Products\ProductGradeResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class NewtypeContentSyncService
{
    public const string SOURCE = 'newtype';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly NewtypeHtmlParser $parser,
        private readonly ProductPdpSearchTermsService $terms,
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
        private readonly ProductGradeResolver $gradeResolver,
    ) {}

    /**
     * @param  callable(string):void|null  $trace  Optional trace sink for UI/debug.
     */
    public function syncForProduct(Product $product, ?string $syncUuid = null, ?callable $trace = null): void
    {
        $sku = is_string($product->sku ?? null) ? trim((string) $product->sku) : '';
        $name = is_string($product->description ?? null) ? trim((string) $product->description) : '';

        $baseCtx = [
            'sync_uuid' => is_string($syncUuid) && trim($syncUuid) !== '' ? trim($syncUuid) : null,
            'product_uuid' => is_string($product->uuid ?? null) ? (string) $product->uuid : null,
            'product_id' => (int) ($product->id ?? 0),
            'sku' => $sku !== '' ? $sku : null,
            'name' => $name !== '' ? $name : null,
            'source' => self::SOURCE,
        ];

        $terms = $this->terms->termsForProduct($product);
        Log::info('newtype.sync.start', [
            ...$baseCtx,
            'terms_count' => count($terms),
        ]);
        $this->trace($trace, 'plan', ['terms_count' => count($terms)]);

        $limit = 10;
        foreach (array_slice($terms, 0, $limit) as $t) {
            $q = trim((string) $t);
            if ($q === '') {
                continue;
            }
            $this->trace($trace, 'plan', ['q' => $q, 'url' => $this->searchUrlForQuery($q)]);
        }
        if (count($terms) > $limit) {
            $this->trace($trace, 'plan', ['truncated' => 'true']);
        }

        if ($terms === []) {
            $this->clearExternal($product);
            $this->trace($trace, 'summary', ['result' => 'no_terms']);

            return;
        }

        $best = $this->resolveBestPdp($terms, $name !== '' ? $name : $sku, $baseCtx, $trace);
        if ($best === null) {
            $this->clearExternal($product);
            $this->trace($trace, 'pdp_not_found', []);
            $this->trace($trace, 'summary', ['result' => 'pdp_not_found']);

            return;
        }

        $this->trace($trace, 'pdp_found', [
            'pdp' => $best['url'],
            'term' => $best['term'],
            'score' => $best['score'],
            'title' => $best['title'],
        ]);

        $pdpRes = $this->http->get($best['url'], siteKey: self::SOURCE);
        if (! $pdpRes->successful()) {
            Log::warning('newtype.sync.pdp.fetch_failed', [
                ...$baseCtx,
                'pdp_url' => $best['url'],
                'http_status' => $pdpRes->status(),
            ]);
            $this->trace($trace, 'pdp_fetch_failed', ['pdp' => $best['url'], 'http' => (string) $pdpRes->status()]);
            $this->trace($trace, 'summary', ['result' => 'pdp_fetch_failed', 'http' => (string) $pdpRes->status()]);

            return;
        }

        $html = (string) $pdpRes->body();
        $parsed = $this->parser->extractDescriptionAndFactsFromPdpHtml($html);
        $imageUrls = $this->parser->extractImageUrlsFromPdpHtml($html);

        $this->trace($trace, 'images_extracted', ['count' => count($imageUrls), 'pdp' => $best['url']]);

        $assetRows = $imageUrls !== []
            ? $this->downloadImageAssets($product, $imageUrls, $best['url'], $baseCtx, $trace)
            : [];

        $this->assets->replaceForProduct((int) $product->id, self::SOURCE, $assetRows);

        $attrs = [
            'scale' => $parsed['scale'] ?? null,
            'line' => $parsed['line'] ?? null,
            'brand' => $parsed['brand'] ?? null,
            'series' => $parsed['series'] ?? null,
        ];
        $attrs = array_filter($attrs, static fn ($v) => is_string($v) && trim($v) !== '');

        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: self::SOURCE,
            title: $parsed['title'] ?? null,
            descriptionHtml: $parsed['description_html'] ?? null,
            attributes: $attrs !== [] ? $attrs : null,
            sourceUrl: $best['url'],
        );

        $this->applyProductFields($product, $parsed);
        $this->products->save($product);

        $this->trace($trace, 'summary', [
            'result' => 'ok',
            'pdp' => $best['url'],
            'images_extracted' => count($imageUrls),
            'images_downloaded' => count($assetRows),
        ]);
    }

    /**
     * @param  array<int, string>  $terms
     * @return array{url: string, title: string, term: string, score: string}|null
     */
    private function resolveBestPdp(array $terms, string $matchName, array $baseCtx, ?callable $trace): ?array
    {
        $matchName = trim($matchName);
        $best = null;
        $bestScore = -1.0;

        foreach ($terms as $term) {
            $q = trim((string) $term);
            if ($q === '') {
                continue;
            }

            $searchUrl = $this->searchUrlForQuery($q);
            $this->trace($trace, 'search_try', ['q' => $q, 'url' => $searchUrl]);

            $res = $this->http->get($searchUrl, siteKey: self::SOURCE);
            if (! $res->successful()) {
                Log::warning('newtype.sync.search.http_failed', [
                    ...$baseCtx,
                    'query' => $q,
                    'search_url' => $searchUrl,
                    'http_status' => $res->status(),
                ]);
                $this->trace($trace, 'search_http_failed', ['q' => $q, 'http' => (string) $res->status()]);

                continue;
            }

            $cands = $this->parser->extractSearchCandidatesFromSearchHtml((string) $res->body());
            if ($cands === []) {
                $this->trace($trace, 'search_no_candidates', ['q' => $q]);

                continue;
            }

            $picked = $this->parser->pickBestCandidate($cands, $q);
            if ($picked === null) {
                $this->trace($trace, 'search_pick_failed', ['q' => $q, 'cands' => (string) count($cands)]);

                continue;
            }

            $score = $this->titleScore($picked['title'], $matchName);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'url' => $picked['url'],
                    'title' => $picked['title'],
                    'term' => $q,
                    'score' => number_format($score, 3, '.', ''),
                ];
            }

            $this->trace($trace, 'search_picked', [
                'q' => $q,
                'cands' => (string) count($cands),
                'pdp' => $picked['url'],
                'score' => number_format($score, 3, '.', ''),
                'title' => $picked['title'],
            ]);
        }

        return $best;
    }

    private function searchUrlForQuery(string $query): string
    {
        $qs = http_build_query(['q' => trim($query)]);

        return NewtypeHtmlParser::BASE_URL.'/search?'.$qs;
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
            if ($url === '') {
                continue;
            }
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
            $filename = "newtype-{$safeSku}-{$index}.{$ext}";
            $storagePath = "newtype/images/{$safeSku}/{$filename}";

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

        Log::info('newtype.sync.images.download_summary', [
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

    /**
     * @param  array{scale:?string,line:?string,brand:?string,series:?string}  $parsed
     */
    private function applyProductFields(Product $product, array $parsed): void
    {
        $scale = is_string($parsed['scale'] ?? null) ? trim((string) $parsed['scale']) : '';
        if ($scale !== '') {
            $product->scale = $scale;
        }

        $line = is_string($parsed['line'] ?? null) ? trim((string) $parsed['line']) : '';
        $grade = $this->gradeFromLine($line, $product->description);
        if ($grade !== null) {
            $product->grade = $grade;
        }

        $series = is_string($parsed['series'] ?? null) ? trim((string) $parsed['series']) : '';
        if ($series !== '') {
            $product->series = $series;
        }

        $brand = is_string($parsed['brand'] ?? null) ? trim((string) $parsed['brand']) : '';
        if ($brand !== '') {
            $product->brand = $brand;
        }
    }

    private function gradeFromLine(string $line, ?string $description = null): ?string
    {
        $line = trim($line);
        if ($line !== '') {
            $fromLine = $this->gradeResolver->resolveFromDescription($line)
                ?? $this->gradeResolver->resolveFromType($line);
            if ($fromLine !== null) {
                return $fromLine;
            }
        }

        if ($description !== null && trim($description) !== '') {
            return $this->gradeResolver->resolveFromDescription($description);
        }

        return null;
    }

    private function titleScore(string $title, string $name): float
    {
        $a = $this->tokens($title);
        $b = $this->tokens($name);
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $hits = 0;
        foreach ($a as $t) {
            if (in_array($t, $b, true)) {
                $hits++;
            }
        }

        return $hits / max(1, count($b));
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\\s+/u', ' ', $s) ?? $s);
        if ($s === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $s), static fn (string $t): bool => $t !== ''));
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
        if ($trace === null) {
            return;
        }
        $parts = [];
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $s = is_string($v) ? $v : (is_numeric($v) ? (string) $v : null);
            if ($s === null || trim($s) === '') {
                continue;
            }
            $s = str_replace(["\r", "\n"], ' ', trim($s));
            if (mb_strlen($s) > 500) {
                $s = mb_substr($s, 0, 500).'…';
            }
            $parts[] = "{$k}={$s}";
        }
        $line = '[newtype]['.$event.']'.($parts !== [] ? ' '.implode(' ', $parts) : '');
        $trace($line);
    }
}
