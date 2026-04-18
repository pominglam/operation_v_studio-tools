<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\Models\Product;
use App\Services\PriceResearch\Http\ExternalHtmlClient;
use Illuminate\Support\Facades\Storage;

final class HljContentSyncService implements HljContentSync
{
    public const string SOURCE = 'hlj';

    public function __construct(
        private readonly ExternalHtmlClient $http,
        private readonly HljHtmlParser $parser,
        private readonly HljImageAcceptanceService $imageAcceptance,
        private readonly HljPdpResolverService $pdpResolver,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
    ) {}

    public function syncForProduct(Product $product): void
    {
        $pdpUrl = $this->pdpResolver->resolvePdpUrlForProduct($product);
        if ($pdpUrl === null) {
            // If we can no longer resolve an HLJ PDP for this product, clear previously stored HLJ content/assets
            // to avoid stale or unrelated data lingering after a recrawl.
            $this->contents->upsertForProduct(
                productId: (int) $product->id,
                source: self::SOURCE,
                title: null,
                descriptionHtml: null,
                attributes: null,
                sourceUrl: null,
            );
            $this->assets->replaceForProduct((int) $product->id, self::SOURCE, []);

            return;
        }

        $pdpRes = $this->http->get($pdpUrl, siteKey: self::SOURCE);
        if (! $pdpRes->successful()) {
            return;
        }

        $html = (string) $pdpRes->body();
        $parsed = $this->parser->extractTitleAndDescription($html);
        if (($parsed['title'] ?? null) === null && ($parsed['description_html'] ?? null) === null) {
            return;
        }

        $this->contents->upsertForProduct(
            productId: (int) $product->id,
            source: self::SOURCE,
            title: $parsed['title'] ?? null,
            descriptionHtml: $parsed['description_html'] ?? null,
            attributes: null,
            sourceUrl: $pdpUrl,
        );

        $expectedCode = $this->parser->productCodeFromPdpUrl($pdpUrl);
        $imageUrls = $this->parser->extractImageUrls($html, $expectedCode);
        if ($imageUrls !== []) {
            $assetRows = $this->downloadImageAssets($product, $imageUrls, $pdpUrl, $expectedCode);
            // Always replace: if we found image URLs but couldn't download any (403, rejected small images, etc),
            // we still want to clear previously stored HLJ assets to avoid stale/unrelated images lingering.
            $this->assets->replaceForProduct((int) $product->id, self::SOURCE, $assetRows);
        }
    }

    /**
     * @param  array<int, string>  $imageUrls
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, origin_url?: string|null, origin_width?: int|null, origin_height?: int|null, checksum_sha256?: string|null}>
     */
    private function downloadImageAssets(Product $product, array $imageUrls, string $pdpUrl, ?string $expectedProductCode): array
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

            $res = $this->http->get($url, [
                'Accept' => 'image/*',
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
            $filename = "hlj-{$safeSku}-{$index}.{$ext}";
            $storagePath = "hlj/images/{$safeSku}/{$filename}";

            $body = $res->body();
            if (! is_string($body) || $body === '') {
                continue;
            }
            $assessment = $this->imageAcceptance->assess($url, $body, $mime, $expectedProductCode);
            if (! $assessment['accept']) {
                continue;
            }

            $disk->put($storagePath, $body);

            $rows[] = [
                'kind' => 'image',
                'storage_path' => $storagePath,
                'filename' => $filename,
                'mime_type' => $mime,
                'size_bytes' => $assessment['size_bytes'],
                'origin_url' => $url,
                'origin_width' => $assessment['width'],
                'origin_height' => $assessment['height'],
                'checksum_sha256' => $assessment['sha256'],
            ];
        }

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
}
