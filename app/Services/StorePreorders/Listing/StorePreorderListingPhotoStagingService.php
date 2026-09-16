<?php

declare(strict_types=1);

namespace App\Services\StorePreorders\Listing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class StorePreorderListingPhotoStagingService
{
    private const DIR = 'store-preorder-listing';

    /**
     * @return array{id: string, preview_url: string}
     */
    public function storeBytes(string $bytes, string $mime, string $filename): array
    {
        $id = (string) Str::uuid();
        $ext = $this->extension($filename, $mime);
        $path = self::DIR.'/'.$id.'.'.$ext;
        Storage::disk('local')->put($path, $bytes);
        $this->writeMeta($id, [
            'path' => $path,
            'mime' => $mime !== '' ? $mime : 'image/jpeg',
            'filename' => $this->safeName($filename, $ext),
        ]);

        return $this->payload($id);
    }

    /**
     * @return array{id: string, preview_url: string}
     */
    public function storeUpload(UploadedFile $file): array
    {
        $mime = is_string($file->getMimeType()) ? $file->getMimeType() : 'image/jpeg';
        $name = trim((string) $file->getClientOriginalName());
        $bytes = (string) file_get_contents($file->getRealPath() ?: $file->getPathname());

        return $this->storeBytes($bytes, $mime, $name !== '' ? $name : 'image.jpg');
    }

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    public function read(string $id): ?array
    {
        $meta = $this->meta($id);
        if ($meta === null) {
            return null;
        }
        $abs = Storage::disk('local')->path($meta['path']);
        if (! is_file($abs)) {
            return null;
        }

        return [
            'path' => $abs,
            'mime' => $meta['mime'],
            'filename' => $meta['filename'],
        ];
    }

    /**
     * @param  list<string>  $ids
     */
    public function forget(array $ids): void
    {
        foreach ($ids as $id) {
            $meta = $this->meta($id);
            if ($meta !== null) {
                Storage::disk('local')->delete($meta['path']);
            }
            Storage::disk('local')->delete($this->metaPath($id));
        }
    }

    /**
     * @return array{id: string, preview_url: string}
     */
    public function payload(string $id): array
    {
        return [
            'id' => $id,
            'preview_url' => '/api/v1/store-preorders/listing-photos/'.$id,
        ];
    }

    /**
     * @return array{path: string, mime: string, filename: string}|null
     */
    private function meta(string $id): ?array
    {
        $id = trim($id);
        if ($id === '' || ! Str::isUuid($id)) {
            return null;
        }
        $raw = Storage::disk('local')->get($this->metaPath($id));
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! is_string($decoded['path'] ?? null)) {
            return null;
        }

        return [
            'path' => (string) $decoded['path'],
            'mime' => is_string($decoded['mime'] ?? null) ? (string) $decoded['mime'] : 'image/jpeg',
            'filename' => is_string($decoded['filename'] ?? null) ? (string) $decoded['filename'] : 'image.jpg',
        ];
    }

    /**
     * @param  array{path: string, mime: string, filename: string}  $meta
     */
    private function writeMeta(string $id, array $meta): void
    {
        Storage::disk('local')->put($this->metaPath($id), json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function metaPath(string $id): string
    {
        return self::DIR.'/'.$id.'.json';
    }

    private function extension(string $filename, string $mime): string
    {
        $fromName = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($fromName, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return $fromName === 'jpeg' ? 'jpg' : $fromName;
        }

        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    private function safeName(string $filename, string $ext): string
    {
        $base = basename(str_replace(['\\', '/'], '-', $filename));
        $base = $base !== '' ? $base : ('image.'.$ext);

        return $base;
    }
}
