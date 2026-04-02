<?php

declare(strict_types=1);

namespace App\Services\Products\Bandai;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use App\Services\Products\ProductPdpSearchTermsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BandaiContentSyncService
{
    public const string SOURCE = 'bandai';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly BandaiHtmlParser $parser,
        private readonly ProductPdpSearchTermsService $terms,
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    public function syncByProductUuid(string $productUuid): bool
    {
        $product = $this->products->findByUuidOrFail($productUuid);
        return $this->syncForProduct($product);
    }

    public function syncForProduct(Product $product): bool
    {
        $best = $this->resolvePdpFromTerms($product);
        if ($best === null) {
            return false;
        }

        $pdpUrl = $best['url'];
        $pdpRes = $this->http->get($pdpUrl, siteKey: self::SOURCE);
        if (! $pdpRes->successful()) {
            return false;
        }

        $parsed = $this->parser->parsePdp((string) $pdpRes->body());
        if ($this->isEmptyParsed($parsed)) {
            return false;
        }

        $assetRows = $this->downloadImageAssets(
            product: $product,
            imageUrls: $parsed['image_urls'],
            pdpUrl: $pdpUrl,
        );

        DB::transaction(function () use ($product, $parsed, $pdpUrl, $assetRows): void {
            $attrs = [
                'bandai_age_text' => $parsed['age_text'],
            ];

            $this->contents->upsertForProduct(
                productId: (int) $product->id,
                source: self::SOURCE,
                title: $parsed['title'],
                descriptionHtml: $parsed['description_html'],
                attributes: $attrs,
                sourceUrl: $pdpUrl,
            );

            $this->applyProductFields($product, $parsed);
            $this->products->save($product);

            $this->assets->replaceForProduct((int) $product->id, self::SOURCE, $assetRows);
        });

        return true;
    }

    /**
     * Try multiple search terms (barcode/sku/name variants) and pick the PDP that matches the product name best.
     *
     * @return array{url: string, title: string}|null
     */
    private function resolvePdpFromTerms(Product $product): ?array
    {
        $name = is_string($product->description) ? trim($product->description) : '';
        $terms = $this->terms->termsForProduct($product);

        // Bandai search is name-based; drop pure digits (barcodes) and very short tokens.
        $terms = array_values(array_filter($terms, static function (string $t): bool {
            $t = trim($t);
            if ($t === '') return false;
            if (ctype_digit($t)) return false;
            return mb_strlen($t) >= 4;
        }));

        // Preferred query: normalized from the product name (keeps grade tokens like "MG").
        $preferred = $this->buildSearchQuery($product);

        // Normalize each term to improve recall and avoid overly-specific model-code tokens.
        $normalized = [];
        if (is_string($preferred) && trim($preferred) !== '') {
            $normalized[] = $preferred;
        }
        foreach ($terms as $t) {
            $q = $this->normalizeSearchQuery($t);
            if (! is_string($q) || trim($q) === '') {
                continue;
            }
            $normalized[] = $q;
        }
        $terms = array_values(array_unique($normalized));

        if ($terms === []) {
            return $preferred !== null ? $this->findBestPdpFromCmsApi($preferred) : null;
        }

        $best = null;
        $bestScore = -1.0;
        foreach ($terms as $q) {
            $cand = $this->findBestPdpFromCmsApi($q);
            if ($cand === null) continue;

            $score = $name !== '' ? $this->titleScore($cand['title'], $name) : 0.0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }

            // Perfect-ish match: stop early.
            if ($bestScore >= 0.95) break;
        }

        return $best;
    }

    private function titleScore(string $title, string $name): float
    {
        $a = $this->tokens($title);
        $b = $this->tokens($name);
        if ($a === [] || $b === []) return 0.0;
        $hits = 0;
        foreach ($a as $t) {
            if (in_array($t, $b, true)) $hits++;
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
        if ($s === '') return [];
        return array_values(array_filter(explode(' ', $s), static fn (string $t): bool => $t !== ''));
    }

    private function buildSearchQuery(Product $product): ?string
    {
        $name = is_string($product->description) ? trim($product->description) : '';
        return $this->normalizeSearchQuery($name);
    }

    private function normalizeSearchQuery(string $query): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        // Remove scale tokens like "1/100" to improve search recall.
        $query = preg_replace('/\b\d+\s*\/\s*\d+\b/u', '', $query) ?? $query;

        // Remove model codes like "MBF-02VV" that can reduce recall.
        $query = preg_replace('/\b[A-Z]{2,}-\d+[A-Z0-9-]*\b/iu', '', $query) ?? $query;
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);

        return $query !== '' ? $query : null;
    }

    private function buildSearchUrl(string $query): string
    {
        $qs = http_build_query([
            'title' => $query,
            'all' => 'on',
        ]);

        return 'https://global.bandai-hobby.net/en-us/search/?'.$qs;
    }

    /**
     * The Bandai search page loads product results via a JSON CMS API.
     * We'll scrape the API token from the page HTML, then call the CMS endpoint (with pagination).
     *
     * @return array{url: string, title: string}|null
     */
    private function findBestPdpFromCmsApi(string $query): ?array
    {
        $searchUrl = $this->buildSearchUrl($query);
        $searchRes = $this->http->get($searchUrl, siteKey: self::SOURCE);
        if (! $searchRes->successful()) {
            return null;
        }

        $searchHtml = (string) $searchRes->body();
        $token = $this->parser->extractCmsApiTokenFromSearchHtml($searchHtml);
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $limit = 20;
        $start = 0;
        $all = [];
        for ($page = 0; $page < 5; $page++) {
            $apiUrl = $this->parser->productListApiUrl($token, $query, $limit, $start);
            if ($apiUrl === null) {
                break;
            }

            $res = $this->http->get($apiUrl, siteKey: self::SOURCE);
            if (! $res->successful()) {
                break;
            }

            $payload = json_decode((string) $res->body(), true);
            if (! is_array($payload)) {
                break;
            }

            $data = $payload['data'] ?? null;
            if (! is_array($data)) {
                break;
            }

            $items = $data['product_list'] ?? null;
            if (! is_array($items) || $items === []) {
                break;
            }

            foreach ($items as $it) {
                if (! is_array($it)) continue;

                $url = $it['url'] ?? $it['detail_url'] ?? $it['detail_path'] ?? null;
                $title = $it['title'] ?? $it['name'] ?? null;
                if (! is_string($url) || ! is_string($title)) continue;

                $url = trim($url);
                $title = trim($title);
                if ($url === '' || $title === '') continue;

                if (! str_starts_with($url, 'http')) {
                    $url = 'https://global.bandai-hobby.net'.(str_starts_with($url, '/') ? '' : '/').$url;
                }
                // Normalize to a trailing slash for stable parsing and consistency with the browser.
                if (! str_ends_with($url, '/')) {
                    $url .= '/';
                }

                if (! str_contains($url, '/en-us/item/')) continue;

                $all[] = ['url' => $url, 'title' => $title];
            }

            $start += $limit;
        }

        if ($all === []) {
            // Fallback: if the page *did* contain the links (e.g. non-JS scenario), reuse the old method.
            $htmlCandidates = $this->parser->extractPdpCandidatesFromSearchHtml($searchHtml);
            $all = $htmlCandidates;
        }

        return $this->parser->pickBestCandidate($all, $query);
    }

    /**
     * @param  array{grade:?string,series:?string,yen_price:?int,launch_date:?CarbonImmutable,age_text:?string,title:?string,description_html:?string,image_urls:array<int,string>}  $parsed
     */
    private function applyProductFields(Product $product, array $parsed): void
    {
        if (is_string($parsed['grade'] ?? null) && trim((string) $parsed['grade']) !== '') {
            $product->grade = trim((string) $parsed['grade']);
        }
        if (is_string($parsed['series'] ?? null) && trim((string) $parsed['series']) !== '') {
            $product->series = trim((string) $parsed['series']);
        }

        $grade = is_string($product->grade) ? trim($product->grade) : '';
        if ($grade !== '') {
            $product->scale = $this->scaleFromGrade($grade);
        }

        if (is_int($parsed['yen_price'] ?? null)) {
            $product->yen_price = $parsed['yen_price'];
        }

        if ($parsed['launch_date'] instanceof CarbonImmutable) {
            $product->bandai_launch_date = $parsed['launch_date'];
        }
    }

    private function scaleFromGrade(string $grade): ?string
    {
        $g = mb_strtoupper(trim($grade));

        return match ($g) {
            'MG' => '1/100',
            'HG' => '1/144',
            'RG' => '1/144',
            'PG' => '1/60',
            'MEGA' => '1/48',
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, checksum_sha256?: string|null}>
     */
    private function downloadImageAssets(Product $product, array $imageUrls, string $pdpUrl): array
    {
        $disk = Storage::disk('local');
        $safeSku = $this->safeSkuDir((string) $product->sku);

        $rows = [];
        $index = 0;
        foreach (array_slice($imageUrls, 0, 30) as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $index++;

            $downloadUrl = $this->normalizeBandaiDownloadUrl($url);
            if ($downloadUrl === null) {
                continue;
            }

            $res = $this->http->get($downloadUrl, [
                'Accept' => 'image/*',
                // Some CDNs behave better with a referer that matches the PDP origin.
                'Referer' => $pdpUrl,
            ], siteKey: self::SOURCE);
            if (! $res->successful()) {
                continue;
            }

            $mime = $res->header('Content-Type');
            $mime = is_string($mime) ? trim(explode(';', $mime)[0]) : null;
            if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                continue;
            }

            $ext = $this->extensionForMime($mime) ?? $this->extensionFromUrl($url) ?? 'jpg';
            $filename = "bandai-{$safeSku}-{$index}.{$ext}";
            $storagePath = "bandai/images/{$safeSku}/{$filename}";

            $body = $res->body();
            if (! is_string($body) || $body === '') {
                continue;
            }

            $sha = hash('sha256', $body);
            $disk->put($storagePath, $body);

            $rows[] = [
                'kind' => 'image',
                'storage_path' => $storagePath,
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => strlen($body),
                'checksum_sha256' => $sha,
            ];
        }

        return $rows;
    }

    /**
     * Bandai embeds CloudFront URLs that can return 403 when fetched server-side.
     * The website itself calls `assets-signedurl(-global).bandai-hobby.net/get-signed-url?path=...`
     * to obtain a fresh signed URL. We mirror that behavior.
     */
    private function normalizeBandaiDownloadUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        if ($host !== '' && str_contains($host, 'cloudfront.net') && str_starts_with($path, '/hobby/')) {
            $signed = $this->fetchBandaiSignedUrl($path);
            if ($signed !== null) {
                return $signed;
            }
        }

        return $url;
    }

    private function fetchBandaiSignedUrl(string $assetPath): ?string
    {
        $assetPath = trim($assetPath);
        if ($assetPath === '' || ! str_starts_with($assetPath, '/')) {
            return null;
        }

        // Heuristic: en-usa assets use the "global" endpoint; jp uses the non-global one.
        $base = str_contains($assetPath, '/en-usa/')
            ? 'https://assets-signedurl-global.bandai-hobby.net/get-signed-url'
            : 'https://assets-signedurl.bandai-hobby.net/get-signed-url';

        $url = $base.'?path='.urlencode($assetPath);
        $res = $this->http->get($url, siteKey: self::SOURCE);
        if (! $res->successful()) {
            return null;
        }

        $payload = json_decode((string) $res->body(), true);
        if (! is_array($payload)) {
            return null;
        }

        $signed = $payload['signedUrl'] ?? $payload['signed_url'] ?? null;
        if (! is_string($signed) || trim($signed) === '') {
            return null;
        }

        return trim($signed);
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
     * @param  array{title:?string,description_html:?string,image_urls:array<int,string>,grade:?string,series:?string,yen_price:?int,launch_date:?CarbonImmutable,age_text:?string}  $parsed
     */
    private function isEmptyParsed(array $parsed): bool
    {
        $hasText = is_string($parsed['description_html'] ?? null) && trim((string) $parsed['description_html']) !== '';
        $hasImgs = is_array($parsed['image_urls'] ?? null) && ($parsed['image_urls'] !== []);
        $hasMeta = (is_string($parsed['grade'] ?? null) && trim((string) $parsed['grade']) !== '')
            || (is_string($parsed['series'] ?? null) && trim((string) $parsed['series']) !== '')
            || is_int($parsed['yen_price'] ?? null)
            || ($parsed['launch_date'] instanceof CarbonImmutable);

        return ! ($hasText || $hasImgs || $hasMeta);
    }
}

