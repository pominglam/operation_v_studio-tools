<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class PlamodPreorderImageService
{
    public const int RETENTION_DAYS = 15;

    public function downloadForSku(string $sku): bool
    {
        $sku = trim($sku);
        if ($sku === '') {
            return false;
        }

        /** @var PlamodPreorder|null $row */
        $row = PlamodPreorder::query()->where('sku', '=', $sku)->first();
        if ($row === null) {
            return false;
        }

        $url = is_string($row->source_image_url) ? trim($row->source_image_url) : '';
        if ($url === '') {
            $row->forceFill(['image_download_status' => PlamodPreorder::IMAGE_STATUS_FAILED])->save();

            return false;
        }

        $row->forceFill(['image_download_status' => 'downloading'])->save();

        try {
            $response = Http::timeout(30)->connectTimeout(10)->get($url);
            if (! $response->successful()) {
                $row->forceFill(['image_download_status' => PlamodPreorder::IMAGE_STATUS_FAILED])->save();

                return false;
            }

            $ext = $this->guessExtension($url, (string) $response->header('Content-Type'));
            $path = 'plamod/preorder-images/'.$this->safeSku($sku).'.'.$ext;
            Storage::disk('local')->put($path, $response->body());

            $row->forceFill([
                'image_storage_path' => $path,
                'image_download_status' => PlamodPreorder::IMAGE_STATUS_COMPLETED,
                'image_downloaded_at' => now(),
            ])->save();

            return true;
        } catch (\Throwable) {
            $row->forceFill(['image_download_status' => PlamodPreorder::IMAGE_STATUS_FAILED])->save();

            return false;
        }
    }

    public function cleanupStaleUnlinkedImages(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $catalogSkus = Product::query()
            ->notArchived()
            ->whereNotNull('sku')
            ->pluck('sku')
            ->map(static fn (mixed $sku): string => trim((string) $sku))
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->all();

        $deleted = 0;
        $rows = PlamodPreorder::query()
            ->whereNotNull('dropped_at')
            ->where('dropped_at', '<=', $cutoff)
            ->whereNotNull('image_storage_path')
            ->get(['id', 'sku', 'image_storage_path']);

        foreach ($rows as $row) {
            if (in_array($row->sku, $catalogSkus, true)) {
                continue;
            }

            $path = (string) $row->image_storage_path;
            if ($path !== '' && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }

            $row->forceFill([
                'image_storage_path' => null,
                'image_downloaded_at' => null,
                'image_download_status' => PlamodPreorder::IMAGE_STATUS_PENDING,
            ])->save();

            $deleted++;
        }

        return $deleted;
    }

    private function safeSku(string $sku): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sku);

        return $safe !== '' ? $safe : 'unknown';
    }

    private function guessExtension(string $url, string $contentType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path)) {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                return $ext === 'jpeg' ? 'jpg' : $ext;
            }
        }

        return match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => 'jpg',
        };
    }
}
