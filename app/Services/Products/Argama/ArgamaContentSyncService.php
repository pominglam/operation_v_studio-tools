<?php

declare(strict_types=1);

namespace App\Services\Products\Argama;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\ProductPdpSearchTermsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ArgamaContentSyncService
{
    public const string SOURCE = 'argama';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly ArgamaHtmlParser $parser,
        private readonly ProductPdpSearchTermsService $terms,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    /**
     * @param  callable(string):void|null  $trace
     */
    public function syncForProduct(Product $product, ?string $syncUuid = null, ?callable $trace = null): void
    {
        $name = is_string($product->description ?? null) ? trim((string) $product->description) : '';
        $sku = is_string($product->sku ?? null) ? trim((string) $product->sku) : '';
        $target = $name !== '' ? $name : $sku;
        $terms = $this->argamaSearchTerms($product, $name);

        $ctx = [
            'sync_uuid' => $syncUuid,
            'product_uuid' => (string) ($product->uuid ?? ''),
            'product_id' => (int) ($product->id ?? 0),
            'sku' => $sku !== '' ? $sku : null,
            'name' => $name !== '' ? $name : null,
            'source' => self::SOURCE,
        ];
        Log::info('argama.sync.start', [...$ctx, 'terms_count' => count($terms)]);
        $this->trace($trace, 'plan', ['terms_count' => count($terms)]);

        $best = $this->resolveBestPdp($terms, $target, $trace);
        if ($best === null) {
            $this->clearExternal($product);
            $this->trace($trace, 'summary', ['result' => 'pdp_not_found']);

            return;
        }

        $this->trace($trace, 'pdp_found', ['pdp' => $best['url'], 'title' => $best['title']]);
        try {
            $pdpRes = $this->http->get($best['url'], siteKey: 'argama_hobby');
        } catch (Throwable $e) {
            $this->trace($trace, 'summary', ['result' => 'pdp_connect_failed', 'message' => $e->getMessage()]);

            return;
        }
        if (! $pdpRes->successful()) {
            $this->trace($trace, 'summary', ['result' => 'pdp_fetch_failed', 'http' => (string) $pdpRes->status()]);

            return;
        }

        $html = (string) $pdpRes->body();
        $imageUrls = $this->parser->extractImageUrlsFromPdpHtml($html);
        $imageUrls = array_map(fn (string $u): string => $this->parser->withWidth($u, 1000), $imageUrls);
        $this->trace($trace, 'images_extracted', ['count' => count($imageUrls)]);

        $assetRows = $this->downloadImageAssets($product, $imageUrls, $best['url'], $trace);
        $this->assets->replaceForProduct((int) $product->id, self::SOURCE, $assetRows);
        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: self::SOURCE,
            title: $best['title'],
            descriptionHtml: null,
            attributes: null,
            sourceUrl: $best['url'],
        );

        $this->trace($trace, 'summary', [
            'result' => 'ok',
            'pdp' => $best['url'],
            'images_extracted' => count($imageUrls),
            'images_downloaded' => count($assetRows),
        ]);
    }

    /**
     * @param  array<int, string>  $terms
     * @return array{url: string, title: string}|null
     */
    private function resolveBestPdp(array $terms, string $targetName, ?callable $trace): ?array
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($terms as $term) {
            $q = trim((string) $term);
            if ($q === '') {
                continue;
            }
            $searchUrl = ArgamaHtmlParser::BASE_URL.'/search?'.http_build_query(['q' => $q]);
            $this->trace($trace, 'search_try', ['q' => $q, 'url' => $searchUrl]);

            try {
                $searchRes = $this->http->get($searchUrl, siteKey: 'argama_hobby');
            } catch (ConnectionException $e) {
                $this->trace($trace, 'search_connect_failed', ['q' => $q, 'message' => $e->getMessage()]);

                continue;
            } catch (Throwable $e) {
                $this->trace($trace, 'search_error', ['q' => $q, 'message' => $e->getMessage()]);

                continue;
            }
            if (! $searchRes->successful()) {
                $this->trace($trace, 'search_http_failed', ['q' => $q, 'http' => (string) $searchRes->status()]);

                continue;
            }

            $candidates = $this->parser->extractSearchCandidatesFromSearchHtml((string) $searchRes->body());
            if ($candidates === []) {
                $this->trace($trace, 'search_no_candidates', ['q' => $q]);

                continue;
            }

            $picked = $this->parser->pickBestCandidate($candidates, $q);
            if ($picked === null) {
                continue;
            }

            $score = $this->titleScore($picked['title'], $targetName);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $picked;
            }
            $this->trace($trace, 'search_picked', [
                'q' => $q,
                'cands' => (string) count($candidates),
                'pdp' => $picked['url'],
                'score' => number_format($score, 3, '.', ''),
            ]);

            // Stop early on a strong match to conserve the rate-limit budget for the
            // PDP + image fetches that follow. 0.8 covers near-perfect token overlap.
            if ($bestScore >= 0.8) {
                $this->trace($trace, 'search_stop_early', ['score' => number_format($bestScore, 3, '.', '')]);
                break;
            }
        }

        return $best;
    }

    /**
     * Argama-tuned search terms: prefer the product title (the strongest signal,
     * since AL imports come from Argama itself) and a couple of broader variants.
     * Skip SKU/barcode searches because Argama's storefront does not index them
     * and they only burn rate-limit budget.
     *
     * @return array<int, string>
     */
    private function argamaSearchTerms(Product $product, string $name): array
    {
        if ($name === '') {
            $sku = is_string($product->sku ?? null) ? trim((string) $product->sku) : '';

            return $sku !== '' ? [$sku] : [];
        }

        $terms = [$name];

        $stripParens = trim(preg_replace('/\([^)]*\)/u', ' ', $name) ?? $name);
        $stripParens = trim(preg_replace('/\s+/u', ' ', $stripParens) ?? $stripParens);
        if ($stripParens !== '' && $stripParens !== $name) {
            $terms[] = $stripParens;
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $terms),
            static fn (string $v): bool => trim($v) !== '',
        )));
    }

    private function titleScore(string $title, string $target): float
    {
        $a = $this->tokens($title);
        $b = $this->tokens($target);
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $hits = 0;
        foreach ($a as $token) {
            if (in_array($token, $b, true)) {
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
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if ($s === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $s), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, origin_url?: string|null, checksum_sha256?: string|null}>
     */
    private function downloadImageAssets(Product $product, array $imageUrls, string $pdpUrl, ?callable $trace): array
    {
        $disk = Storage::disk('local');
        $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $product->sku) ?: 'unknown';
        $rows = [];
        $index = 0;

        foreach (array_slice($imageUrls, 0, 40) as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $index++;

            $res = $this->http->get($url, ['Accept' => 'image/*', 'Referer' => $pdpUrl], siteKey: 'argama_hobby');
            if (! $res->successful()) {
                continue;
            }

            $mime = $res->header('Content-Type');
            $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                continue;
            }

            $body = $res->body();
            if (! is_string($body) || $body === '') {
                continue;
            }

            $ext = match ($mime) {
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg'),
            };
            $filename = "argama-{$safeSku}-{$index}.{$ext}";
            $storagePath = "argama/images/{$safeSku}/{$filename}";
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

        $this->trace($trace, 'images_downloaded', ['downloaded' => count($rows)]);

        return $rows;
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
            $s = is_string($v) ? $v : (is_numeric($v) ? (string) $v : null);
            if ($s === null || trim($s) === '') {
                continue;
            }
            $parts[] = "{$k}=".str_replace(["\r", "\n"], ' ', trim($s));
        }
        $trace('[argama]['.$event.']'.($parts !== [] ? ' '.implode(' ', $parts) : ''));
    }
}
