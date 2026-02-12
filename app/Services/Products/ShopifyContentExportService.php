<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\Models\Product;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Services\Shopify\ShopifyImageUrlSigner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ShopifyContentExportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductExportService $exports,
        private readonly ShopifyImageUrlSigner $signer,
    ) {}

    /**
     * @return array{
     *   export_id: string,
     *   storage_path: string,
     *   exported_products: int,
     *   exported_rows: int,
     *   skipped_missing_handle: array<int, array{sku:string, description:string}>,
     *   skipped_duplicate_handle: array<int, array{sku:string, description:string, handle:string}>,
     *   tunnel_base_url: string|null,
     *   images_enabled: bool
     * }
     */
    public function prepare(?string $tunnelBaseUrl, array $productUuids = []): array
    {
        $exportId = (string) Str::uuid();
        $storagePath = "exports/shopify_content/{$exportId}.csv";

        $header = $this->exports->shopifyHeader();
        /** @var array<string, int> $idx */
        $idx = array_flip($header);

        $disk = Storage::disk('local');
        $disk->makeDirectory('exports/shopify_content');
        $abs = $disk->path($storagePath);

        $fh = fopen($abs, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Failed to create export file.');
        }

        $imagesEnabled = is_string($tunnelBaseUrl) && trim($tunnelBaseUrl) !== '';
        $tunnelBaseUrl = $imagesEnabled ? rtrim(trim($tunnelBaseUrl), '/') : null;

        $exportedProducts = 0;
        $exportedRows = 0;

        $skippedMissingHandle = [];
        $skippedDuplicateHandle = [];

        $usedHandles = [];

        try {
            fputcsv($fh, $header);

            $list = $productUuids === []
                ? $this->products->listForShopifyContentExport()
                : $this->products->listForShopifyContentExportByUuids($productUuids);

            foreach ($list as $product) {
                $handle = $this->ensureHandle($product, $usedHandles);
                if ($handle === null) {
                    $skippedMissingHandle[] = [
                        'sku' => (string) $product->sku,
                        'description' => (string) $product->description,
                    ];
                    continue;
                }

                if (isset($usedHandles[$handle]) && $usedHandles[$handle] !== $product->uuid) {
                    $skippedDuplicateHandle[] = [
                        'sku' => (string) $product->sku,
                        'description' => (string) $product->description,
                        'handle' => $handle,
                    ];
                    continue;
                }
                $usedHandles[$handle] = $product->uuid;

                $bodyHtml = $this->normalizeBodyHtmlForShopify($this->resolveBodyHtml($product));

                $row = $this->exports->shopifyRow($product, $handle);
                $row[$idx['Body (HTML)']] = $bodyHtml;

                $images = $this->imagesForProduct($product);
                if ($imagesEnabled && $images !== []) {
                    $img1 = $images[0];
                    $row[$idx['Image Src']] = $this->signedImageUrl($img1, $tunnelBaseUrl);
                    $row[$idx['Image Position']] = '1';
                    $row[$idx['Image Alt Text']] = (string) $product->description;
                }

                fputcsv($fh, $row);
                $exportedRows++;
                $exportedProducts++;

                if ($imagesEnabled && count($images) > 1) {
                    $pos = 1;
                    foreach (array_slice($images, 1) as $img) {
                        $pos++;
                        $blank = array_fill(0, count($header), '');
                        $blank[$idx['Handle']] = $handle;
                        $blank[$idx['Image Src']] = $this->signedImageUrl($img, $tunnelBaseUrl);
                        $blank[$idx['Image Position']] = (string) $pos;
                        $blank[$idx['Image Alt Text']] = (string) $product->description;
                        fputcsv($fh, $blank);
                        $exportedRows++;
                    }
                }
            }
        } finally {
            fclose($fh);
        }

        return [
            'export_id' => $exportId,
            'storage_path' => $storagePath,
            'exported_products' => $exportedProducts,
            'exported_rows' => $exportedRows,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_duplicate_handle' => $skippedDuplicateHandle,
            'tunnel_base_url' => $tunnelBaseUrl,
            'images_enabled' => $imagesEnabled,
        ];
    }

    private function ensureHandle(Product $product, array &$usedHandles): ?string
    {
        $existing = is_string($product->handle) ? trim($product->handle) : '';
        if ($existing !== '') {
            return $existing;
        }

        $tmpUsed = [];
        foreach ($usedHandles as $h => $uuid) {
            $tmpUsed[(string) $h] = true;
        }

        $generated = $this->exports->shopifyHandleForProduct($product, $tmpUsed);
        $generated = trim($generated);
        if ($generated === '') {
            return null;
        }

        // Persist the canonical handle so future exports do not regenerate.
        $product->handle = $generated;
        $this->products->save($product);

        return $generated;
    }

    /**
     * HLJ -> competitor -> Plamod -> empty
     */
    private function resolveBodyHtml(Product $product): string
    {
        $preferred = is_string($product->preferred_description_source) ? trim($product->preferred_description_source) : '';
        if ($preferred !== '') {
            // If user has a preference, use it when it has non-empty HTML.
            if ($preferred === 'hlj') {
                $hlj = $product->hljExternalContent?->description_html;
                if (is_string($hlj) && trim($hlj) !== '') {
                    return $hlj;
                }
            }
            if ($preferred === 'plamod') {
                $plamod = $product->plamodExternalContent?->description_html;
                if (is_string($plamod) && trim($plamod) !== '') {
                    return $plamod;
                }
            }

            /** @var array<int, ProductExternalContent> $contents */
            $contents = $product->externalContents?->all() ?? [];
            foreach ($contents as $c) {
                if (! $c instanceof ProductExternalContent) continue;
                if ($c->source !== $preferred) continue;
                if (! is_string($c->description_html) || trim($c->description_html) === '') continue;
                return (string) $c->description_html;
            }
        }

        $hlj = $product->hljExternalContent?->description_html;
        if (is_string($hlj) && trim($hlj) !== '') {
            return $hlj;
        }

        /** @var array<int, ProductExternalContent> $contents */
        $contents = $product->externalContents?->all() ?? [];
        $best = null;
        foreach ($contents as $c) {
            if (! $c instanceof ProductExternalContent) continue;
            if (in_array($c->source, ['hlj', 'plamod'], true)) continue;
            if (! is_string($c->description_html) || trim($c->description_html) === '') continue;

            if ($best === null) {
                $best = $c;
                continue;
            }
            $bestAt = $best->updated_at?->getTimestamp() ?? 0;
            $cAt = $c->updated_at?->getTimestamp() ?? 0;
            if ($cAt >= $bestAt) {
                $best = $c;
            }
        }
        if ($best !== null && is_string($best->description_html) && trim($best->description_html) !== '') {
            return (string) $best->description_html;
        }

        $plamod = $product->plamodExternalContent?->description_html;
        if (is_string($plamod) && trim($plamod) !== '') {
            return $plamod;
        }

        return '';
    }

    private function normalizeBodyHtmlForShopify(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Shopify already renders <p> blocks with spacing; <br> tends to create extra blank lines.
        $html = preg_replace('#<\s*br\s*/?\s*>#i', '', $html) ?? $html;

        // Common encoding artifact: "Â " (NBSP mis-decoded) – replace with a regular space.
        $html = str_replace("Â ", ' ', $html);

        // Clean up excessive whitespace between tags.
        $html = preg_replace("/[ \\t\\r\\n]+/", ' ', $html) ?? $html;
        $html = trim($html);

        return $html;
    }

    /**
     * @return array<int, ProductExternalAsset>
     */
    private function imagesForProduct(Product $product): array
    {
        $imgs = $product->shopifyImageAssets?->all() ?? [];
        /** @var array<int, ProductExternalAsset> $out */
        $out = [];
        foreach ($imgs as $a) {
            if ($a instanceof ProductExternalAsset) {
                $out[] = $a;
            }
        }
        return $out;
    }

    private function signedImageUrl(ProductExternalAsset $asset, string $tunnelBaseUrl): string
    {
        $tunnelBaseUrl = rtrim($tunnelBaseUrl, '/');
        $expires = now()->addHours(72)->getTimestamp();
        $signed = $this->signer->sign((int) $asset->id, $expires);

        // IMPORTANT: Do not use query-string signatures here.
        // Shopify CSV imports may strip query parameters from Image Src URLs.
        $filename = is_string($asset->filename) && trim($asset->filename) !== '' ? basename($asset->filename) : 'image.png';
        $filename = rawurlencode($filename);
        return $tunnelBaseUrl.'/shopify-images/'.$asset->id.'/'.$signed['expires'].'/'.$signed['signature'].'/'.$filename;
    }
}


