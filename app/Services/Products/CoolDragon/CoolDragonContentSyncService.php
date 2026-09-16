<?php

declare(strict_types=1);

namespace App\Services\Products\CoolDragon;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\Argama\ArgamaHtmlParser;
use App\Support\PriceResearch\CoolDragonSearchTerms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CoolDragonContentSyncService
{
    public const string SOURCE = 'cool_dragon';

    public const string SITE_KEY = 'cool_dragon_hobby';

    private readonly ArgamaHtmlParser $parser;

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
    ) {
        $base = rtrim((string) config('price_research.sites.cool_dragon_hobby.base_url', 'https://cooldragonhobby.ca'), '/');
        $this->parser = new ArgamaHtmlParser($base !== '' ? $base : 'https://cooldragonhobby.ca');
    }

    /**
     * @param  callable(string):void|null  $trace
     */
    public function syncForProduct(Product $product, ?string $syncUuid = null, ?callable $trace = null): void
    {
        $name = is_string($product->description ?? null) ? trim((string) $product->description) : '';
        $sku = is_string($product->sku ?? null) ? trim((string) $product->sku) : '';
        $target = $name !== '' ? $name : $sku;
        $terms = $this->searchTerms($name, $sku);

        Log::info('cool_dragon.sync.start', [
            'sync_uuid' => $syncUuid,
            'sku' => $sku !== '' ? $sku : null,
            'terms_count' => count($terms),
        ]);
        $this->trace($trace, 'plan', ['terms_count' => (string) count($terms)]);

        $best = $this->resolveBestPdp($terms, $target, $trace);
        if ($best === null) {
            $this->clearExternal($product);
            $this->trace($trace, 'summary', ['result' => 'pdp_not_found']);

            return;
        }

        $this->finishPdpSync($product, $best, $trace);
    }

    /**
     * @param  array{url: string, title: string}  $best
     * @param  callable(string):void|null  $trace
     */
    private function finishPdpSync(Product $product, array $best, ?callable $trace): void
    {
        $this->trace($trace, 'pdp_found', ['pdp' => $best['url'], 'title' => $best['title']]);
        try {
            $pdpRes = $this->http->get($best['url'], siteKey: self::SITE_KEY);
        } catch (Throwable $e) {
            $this->trace($trace, 'summary', ['result' => 'pdp_connect_failed', 'message' => $e->getMessage()]);

            return;
        }
        if (! $pdpRes->successful()) {
            $this->trace($trace, 'summary', ['result' => 'pdp_fetch_failed', 'http' => (string) $pdpRes->status()]);

            return;
        }

        $imageUrls = $this->parser->extractImageUrlsFromPdpHtml((string) $pdpRes->body());
        $imageUrls = array_map(fn (string $u): string => $this->parser->withWidth($u, 1000), $imageUrls);
        $assetRows = $this->downloadImageAssets($product, $imageUrls, $best['url']);
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
            'images_downloaded' => (string) count($assetRows),
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
        $base = $this->parser->storefrontBaseUrl();

        foreach ($terms as $term) {
            $q = trim((string) $term);
            if ($q === '') {
                continue;
            }
            $picked = $this->searchOneTerm($q, $base, $trace);
            if ($picked === null) {
                continue;
            }
            $score = $this->titleScore($picked['title'], $targetName);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $picked;
            }
            if ($bestScore >= 0.8) {
                break;
            }
        }

        return $best;
    }

    /**
     * @return array{url: string, title: string}|null
     */
    private function searchOneTerm(string $q, string $base, ?callable $trace): ?array
    {
        $fromSuggest = $this->searchSuggest($q, $base, $trace);
        if ($fromSuggest !== null) {
            return $fromSuggest;
        }

        $searchUrl = $base.'/search?'.http_build_query(['q' => $q]);
        $this->trace($trace, 'search_try', ['q' => $q, 'url' => $searchUrl]);
        try {
            $searchRes = $this->http->get($searchUrl, siteKey: self::SITE_KEY);
        } catch (ConnectionException|Throwable $e) {
            $this->trace($trace, 'search_error', ['q' => $q, 'message' => $e->getMessage()]);

            return null;
        }
        if (! $searchRes->successful()) {
            return null;
        }
        $candidates = $this->parser->extractSearchCandidatesFromSearchHtml((string) $searchRes->body());

        return $candidates === [] ? null : $this->parser->pickBestCandidate($candidates, $q);
    }

    /**
     * @return array{url: string, title: string}|null
     */
    private function searchSuggest(string $q, string $base, ?callable $trace): ?array
    {
        $suggestUrl = $base.'/search/suggest.json?'.http_build_query([
            'q' => $q,
            'resources' => [
                'type' => 'product',
                'limit' => 12,
                'options' => ['unavailable_products' => 'show'],
            ],
        ]);
        $this->trace($trace, 'suggest_try', ['q' => $q, 'url' => $suggestUrl]);
        try {
            $res = $this->http->get($suggestUrl, ['Accept' => 'application/json, text/plain, */*'], siteKey: self::SITE_KEY);
        } catch (ConnectionException|Throwable $e) {
            $this->trace($trace, 'suggest_error', ['q' => $q, 'message' => $e->getMessage()]);

            return null;
        }
        if (! $res->successful()) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $products */
        $products = Arr::get($res->json(), 'resources.results.products', []);
        if (! is_array($products) || $products === []) {
            return null;
        }

        $candidates = [];
        foreach ($products as $product) {
            $title = trim((string) ($product['title'] ?? ''));
            $rel = trim((string) ($product['url'] ?? ''));
            if ($title === '' || $rel === '') {
                continue;
            }
            $path = parse_url($rel, PHP_URL_PATH);
            $path = is_string($path) && $path !== '' ? $path : $rel;
            $url = $this->parser->storefrontBaseUrl().'/'.ltrim($path, '/');
            $candidates[] = ['url' => $url, 'title' => $title];
        }

        return $candidates === [] ? null : $this->parser->pickBestCandidate($candidates, $q);
    }

    /**
     * @return array<int, string>
     */
    private function searchTerms(string $name, string $sku): array
    {
        $clean = CoolDragonSearchTerms::fromTitle($name);
        if ($clean === '') {
            return $sku !== '' ? [$sku] : [];
        }
        $terms = [$clean];
        $withoutBrand = trim(preg_replace('/^SNAA\s+/i', '', $clean) ?? $clean);
        if ($withoutBrand !== '' && $withoutBrand !== $clean) {
            $terms[] = $withoutBrand;
        }

        return array_values(array_unique(array_filter($terms, static fn (string $v): bool => trim($v) !== '')));
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
        $s = trim(preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return $s === '' ? [] : array_values(array_filter(explode(' ', $s)));
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, origin_url?: string|null, checksum_sha256?: string|null}>
     */
    private function downloadImageAssets(Product $product, array $imageUrls, string $pdpUrl): array
    {
        $disk = Storage::disk('local');
        $safeSku = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $product->sku) ?: 'unknown';
        $rows = [];
        $index = 0;
        foreach (array_slice($imageUrls, 0, 40) as $url) {
            $row = $this->downloadOneImage($url, $pdpUrl, $safeSku, ++$index, $disk);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, origin_url?: string|null, checksum_sha256?: string|null}|null
     */
    private function downloadOneImage(string $url, string $pdpUrl, string $safeSku, int $index, mixed $disk): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $res = $this->http->get($url, ['Accept' => 'image/*', 'Referer' => $pdpUrl], siteKey: self::SITE_KEY);
        if (! $res->successful()) {
            return null;
        }
        $mime = $res->header('Content-Type');
        $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
        $body = $res->body();
        if (! is_string($mime) || ! str_starts_with($mime, 'image/') || ! is_string($body) || $body === '') {
            return null;
        }
        $ext = match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        $filename = "cool-dragon-{$safeSku}-{$index}.{$ext}";
        $storagePath = "cool_dragon/images/{$safeSku}/{$filename}";
        $disk->put($storagePath, $body);

        return [
            'kind' => 'image',
            'storage_path' => $storagePath,
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => strlen($body),
            'origin_url' => $url,
            'checksum_sha256' => hash('sha256', $body),
        ];
    }

    private function clearExternal(Product $product): void
    {
        $this->contents->upsertForProduct((int) $product->id, self::SOURCE, null, null, null, null);
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
        $trace('[cool_dragon]['.$event.']'.($parts !== [] ? ' '.implode(' ', $parts) : ''));
    }
}
