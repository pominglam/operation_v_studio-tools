<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductExternalAssetRepository;
use App\DAL\Products\ProductExternalContentRepository;
use App\DAL\Products\ProductRepository;
use App\DTOs\Products\PlamodSyncResult;
use App\Models\Product;
use App\Models\ProductExternalContent;
use App\Services\Products\Exceptions\PlamodSyncException;
use App\Services\Products\Hlj\HljContentSync;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PlamodAssetSyncService
{
    public const string SOURCE = 'plamod';

    public function __construct(
        private readonly PlamodFirstSyncBackupService $backupGate,
        private readonly ProductRepository $products,
        private readonly ProductExternalContentRepository $contents,
        private readonly ProductExternalAssetRepository $assets,
        private readonly PlamodZipDownloadService $zipDownloads,
        private readonly PlamodAssetFilenameService $assetFilenames,
        private readonly HljContentSync $hlj,
    ) {}

    public function syncByProductUuid(string $productUuid, bool $attemptPlamodAssets = false): PlamodSyncResult
    {
        $backup = $this->backupGate->ensureBackupExists();
        $product = $this->products->findByUuidOrFail($productUuid);

        // By default, only Plamod-vendor products attempt Plamod asset download.
        // For other vendors, we still do a best-effort HLJ description sync and return.
        // Manual “Get product info” can override this with $attemptPlamodAssets=true.
        if (! $attemptPlamodAssets && ($product->vendor ?? null) !== 'Plamod') {
            try {
                $this->hlj->syncForProduct($product);
            } catch (\Throwable) {
                // Ignore; this is best-effort.
            }

            $hljContent = $this->contents->findForProduct($product->id, 'hlj');

            return new PlamodSyncResult(
                backupCreated: $backup['created'],
                backup: $backup['backup'],
                content: $hljContent,
                assets: [],
            );
        }

        $download = $this->zipDownloads->downloadZip($product->sku);
        if (! ($download['ok'] ?? false)) {
            $msg = (string) ($download['error_message'] ?? 'Plamod download failed');
            $debug = is_array($download['debug'] ?? null) ? $download['debug'] : null;
            if (is_array($debug)) {
                $url = is_string($debug['current_url'] ?? null) ? (string) $debug['current_url'] : null;
                $png = is_string($debug['debug_png_storage_path'] ?? null) ? (string) $debug['debug_png_storage_path'] : null;
                $html = is_string($debug['debug_html_storage_path'] ?? null) ? (string) $debug['debug_html_storage_path'] : null;

                $cookieNames = is_array($debug['cookie_names'] ?? null) ? $debug['cookie_names'] : null;
                $netlog = is_array($debug['netlog'] ?? null) ? $debug['netlog'] : null;
                $loginError = is_string($debug['login_error'] ?? null) ? (string) $debug['login_error'] : null;
                $localStorageKeys = is_array($debug['local_storage_keys'] ?? null) ? $debug['local_storage_keys'] : null;

                $extra = [];
                if ($url) {
                    $extra[] = "url={$url}";
                }
                if ($png) {
                    $extra[] = "png={$png}";
                }
                if ($html) {
                    $extra[] = "html={$html}";
                }

                if (is_array($cookieNames)) {
                    $names = array_values(array_filter($cookieNames, fn ($v) => is_string($v) && $v !== ''));
                    $extra[] = 'cookies='.count($names);
                    if (! empty($names)) {
                        $extra[] = 'cookie_names='.implode('|', array_slice($names, 0, 10));
                    }
                }

                if ($loginError) {
                    $extra[] = 'login_error='.$loginError;
                }

                if (is_array($localStorageKeys)) {
                    $keys = array_values(array_filter($localStorageKeys, fn ($v) => is_string($v) && $v !== ''));
                    $extra[] = 'ls_keys='.count($keys);
                    if (! empty($keys)) {
                        $extra[] = 'ls_key_names='.implode('|', array_slice($keys, 0, 10));
                    }
                }

                if (is_array($netlog)) {
                    $responses = is_array($netlog['responses'] ?? null) ? $netlog['responses'] : [];
                    $tail = array_slice(is_array($responses) ? $responses : [], -25);
                    $summaries = [];
                    foreach ($tail as $r) {
                        if (! is_array($r)) {
                            continue;
                        }
                        $method = is_string($r['method'] ?? null) ? (string) $r['method'] : '?';
                        $status = is_int($r['status'] ?? null) ? (int) $r['status'] : null;
                        $u = is_string($r['url'] ?? null) ? (string) $r['url'] : '';
                        $path = $u !== '' ? parse_url($u, PHP_URL_PATH) : null;

                        // Keep the message concise: focus on non-GET or non-2xx, but include a few GETs as context.
                        $isInteresting = ($method !== 'GET') || ($status !== null && $status >= 300);
                        if ($isInteresting || count($summaries) < 8) {
                            $summaries[] = $method.' '.($status ?? '?').' '.($path ?: '');
                        }
                    }
                    if (! empty($summaries)) {
                        $extra[] = 'netlog='.implode('; ', $summaries);
                    }
                }

                if (! empty($extra)) {
                    $msg .= ' (debug: '.implode(', ', $extra).')';
                }
            }
            // In bulk “sync missing PDP info”, many products may not have a Plamod ZIP.
            // Treat “missing Download ZIP” as a non-fatal condition so the batch can progress.
            if (str_contains($msg, 'Download ZIP')) {
                try {
                    $this->hlj->syncForProduct($product);
                } catch (\Throwable) {
                    // Ignore; best-effort.
                }

                $hljContent = $this->contents->findForProduct($product->id, 'hlj');

                return new PlamodSyncResult(
                    backupCreated: $backup['created'],
                    backup: $backup['backup'],
                    content: $hljContent,
                    assets: [],
                );
            }

            throw PlamodSyncException::downloadFailed($msg);
        }

        $zipStoragePath = (string) ($download['zip_storage_path'] ?? '');
        if ($zipStoragePath === '' || ! Storage::disk('local')->exists($zipStoragePath)) {
            throw PlamodSyncException::zipMissing();
        }

        $extractedPaths = $this->extractZipToStorage($product->sku, $zipStoragePath);
        $metadata = is_array($download['metadata'] ?? null) ? $download['metadata'] : [];

        $content = DB::transaction(function () use ($product, $metadata): ProductExternalContent {
            $title = is_string($metadata['title'] ?? null) ? (string) $metadata['title'] : null;
            $desc = is_string($metadata['description_html'] ?? null) ? (string) $metadata['description_html'] : null;
            $attrs = is_array($metadata['attributes'] ?? null) ? $metadata['attributes'] : null;

            $sku = is_string($product->sku) ? trim($product->sku) : '';
            $plamodUrl = $sku !== '' ? ('https://plamod.com/retailer/products/'.rawurlencode($sku)) : null;

            return $this->contents->upsertForProduct(
                productId: $product->id,
                source: self::SOURCE,
                title: $title,
                descriptionHtml: $desc,
                attributes: $attrs,
                sourceUrl: $plamodUrl,
            );
        });

        $assetRows = $this->buildAssetRows($zipStoragePath, $extractedPaths);
        $assets = $this->assets->replaceForProduct($product->id, self::SOURCE, $assetRows);

        // Immediately rename image files to SEO-friendly filenames (ASCII-only).
        // This updates both the on-disk path and the stored filename used by downloads.
        $this->assetFilenames->renameImageAssetsForProductUuid($product->uuid);
        $assets = $this->assets->listForProduct($product->id, self::SOURCE);

        // Best-effort: store a generic manufacturer/distributor-style description from HLJ
        // so the PDP preview can show something even if Plamod doesn't provide text.
        try {
            $this->hlj->syncForProduct($product);
        } catch (\Throwable) {
            // Ignore; Plamod assets are the primary goal of this job.
        }

        return new PlamodSyncResult(
            backupCreated: $backup['created'],
            backup: $backup['backup'],
            content: $content,
            assets: $assets,
        );
    }

    /**
     * @return array<int, string> storage paths (relative to storage/app)
     */
    private function extractZipToStorage(string $productSku, string $zipStoragePath): array
    {
        $disk = Storage::disk('local');

        $zipAbs = $disk->path($zipStoragePath);
        $baseDir = $this->newExtractBaseDir($productSku);
        $this->ensureStorageDirExists($disk->path($baseDir));

        $zip = new \ZipArchive;
        if ($zip->open($zipAbs) !== true) {
            throw PlamodSyncException::zipOpenFailed();
        }

        $written = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $safe = $this->sanitizeZipEntryPath($name);
                if ($safe === null) {
                    continue;
                }

                $targetStoragePath = $baseDir.'/'.$safe;
                $this->extractZipEntry($zip, $disk, $name, $targetStoragePath);
                $written[] = $targetStoragePath;
            }
        } finally {
            $zip->close();
        }

        return $written;
    }

    private function newExtractBaseDir(string $productSku): string
    {
        $batch = now()->format('Ymd-His');

        return 'plamod/extracted/'.$this->safeSkuDir($productSku).'/'.$batch;
    }

    private function ensureStorageDirExists(string $absDir): void
    {
        if (is_dir($absDir)) {
            return;
        }

        @mkdir($absDir, 0777, true);
    }

    private function extractZipEntry(\ZipArchive $zip, FilesystemAdapter $disk, string $zipEntryName, string $targetStoragePath): void
    {
        $targetAbs = $disk->path($targetStoragePath);
        $this->ensureStorageDirExists(dirname($targetAbs));

        $stream = $zip->getStream($zipEntryName);
        if ($stream === false) {
            return;
        }

        try {
            $contents = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            return;
        }

        file_put_contents($targetAbs, $contents);
    }

    /**
     * @param  array<int, string>  $extractedStoragePaths
     * @return array<int, array{kind: string, storage_path: string, filename: string, mime_type?: string|null, size_bytes?: int|null, checksum_sha256?: string|null}>
     */
    private function buildAssetRows(string $zipStoragePath, array $extractedStoragePaths): array
    {
        $disk = Storage::disk('local');
        $rows = [];

        $rows[] = [
            'kind' => 'zip',
            'storage_path' => $zipStoragePath,
            'filename' => basename($zipStoragePath),
            'mime_type' => $disk->mimeType($zipStoragePath) ?: 'application/zip',
            'size_bytes' => $disk->size($zipStoragePath) ?: null,
        ];

        foreach ($extractedStoragePaths as $p) {
            $kind = $this->kindFromPath($p);
            $sha = null;
            if ($kind === 'image' && $disk->exists($p)) {
                $abs = $disk->path($p);
                $sha = is_string($abs) && $abs !== '' ? (hash_file('sha256', $abs) ?: null) : null;
            }
            $rows[] = [
                'kind' => $kind,
                'storage_path' => $p,
                'filename' => basename($p),
                'mime_type' => $disk->mimeType($p) ?: null,
                'size_bytes' => $disk->size($p) ?: null,
                'checksum_sha256' => $sha,
            ];
        }

        return $rows;
    }

    private function kindFromPath(string $storagePath): string
    {
        $ext = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return 'image';
        }
        if ($ext === 'pdf') {
            return 'pdf';
        }

        return 'other';
    }

    private function safeSkuDir(string $sku): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $sku) ?: 'unknown';
    }

    private function sanitizeZipEntryPath(string $name): ?string
    {
        $n = str_replace('\\', '/', $name);
        $n = ltrim($n, '/');
        if ($n === '' || str_ends_with($n, '/')) {
            return null;
        }
        if (str_contains($n, '../') || str_contains($n, '..\\')) {
            return null;
        }

        // Avoid very long names
        if (strlen($n) > 240) {
            return null;
        }

        return $n;
    }
}
