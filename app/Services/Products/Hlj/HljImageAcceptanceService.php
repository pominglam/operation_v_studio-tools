<?php

declare(strict_types=1);

namespace App\Services\Products\Hlj;

use Illuminate\Support\Str;

final class HljImageAcceptanceService
{
    /**
     * Hard ban list for known “garbage” images (shipping/payment/logo placeholders).
     * These hashes are of the downloaded image bytes (sha256).
     *
     * @var array<int, string>
     */
    private const array BANNED_SHA256 = [
        // Intentionally empty to start. We’ll populate once we observe stable hashes from the wild.
    ];

    /**
     * @return array{accept: bool, reason: string, sha256: string, width: int|null, height: int|null, size_bytes: int}
     */
    public function assess(string $originUrl, string $bytes, string $mimeType, ?string $expectedProductCode): array
    {
        $originUrl = trim($originUrl);
        $bytesLen = strlen($bytes);
        $sha = hash('sha256', $bytes);

        if ($bytesLen < 10_000) {
            return [
                'accept' => false,
                'reason' => 'too_small_bytes',
                'sha256' => $sha,
                'width' => null,
                'height' => null,
                'size_bytes' => $bytesLen,
            ];
        }

        if (in_array($sha, self::BANNED_SHA256, true)) {
            return [
                'accept' => false,
                'reason' => 'banned_sha256',
                'sha256' => $sha,
                'width' => null,
                'height' => null,
                'size_bytes' => $bytesLen,
            ];
        }

        // If we know the expected PDP code (banh663085-up, bann22236, etc), enforce that for /productimages/ URLs.
        // HLJ also serves some legitimate product images under /media/catalog/product/ that do not include the PDP code.
        $expected = is_string($expectedProductCode) ? strtolower(trim($expectedProductCode)) : '';
        if ($expected !== '' && $originUrl !== '') {
            $path = parse_url($originUrl, PHP_URL_PATH);
            $path = is_string($path) ? strtolower($path) : '';

            if ($path !== '' && Str::contains($path, '/productimages/')) {
                $expectedNoUp = preg_replace('/-up$/', '', $expected) ?? $expected;
                $matchesCode = Str::contains($path, $expected)
                    || ($expectedNoUp !== '' && Str::contains($path, $expectedNoUp));

                if (! $matchesCode) {
                    return [
                        'accept' => false,
                        'reason' => 'unexpected_product_code_in_url',
                        'sha256' => $sha,
                        'width' => null,
                        'height' => null,
                        'size_bytes' => $bytesLen,
                    ];
                }
            }
        }

        $dims = @getimagesizefromstring($bytes);
        $width = is_array($dims) && isset($dims[0]) ? (int) $dims[0] : null;
        $height = is_array($dims) && isset($dims[1]) ? (int) $dims[1] : null;

        if (! is_int($width) || $width <= 0 || ! is_int($height) || $height <= 0) {
            return [
                'accept' => false,
                'reason' => 'invalid_image',
                'sha256' => $sha,
                'width' => null,
                'height' => null,
                'size_bytes' => $bytesLen,
            ];
        }

        // Very small dimensions are almost always UI icons/logos.
        if (min($width, $height) < 200) {
            return [
                'accept' => false,
                'reason' => 'too_small_dimensions',
                'sha256' => $sha,
                'width' => $width,
                'height' => $height,
                'size_bytes' => $bytesLen,
            ];
        }

        // Content-level heuristic that doesn’t depend on filenames:
        // “Garbage” banners/logos are usually very compressible (low entropy) and very wide/tall.
        $ratio = $this->compressionRatio($bytes);
        $aspect = max($width / max(1, $height), $height / max(1, $width));

        // Wide banner + low entropy => likely shipping/payment/logo placeholder.
        if ($aspect >= 2.0 && $ratio !== null && $ratio <= 0.22) {
            return [
                'accept' => false,
                'reason' => 'banner_like_low_entropy',
                'sha256' => $sha,
                'width' => $width,
                'height' => $height,
                'size_bytes' => $bytesLen,
            ];
        }

        // Extremely low entropy is suspicious even when not a banner.
        if ($ratio !== null && $ratio <= 0.14 && ($width >= 350 || $height >= 350)) {
            return [
                'accept' => false,
                'reason' => 'very_low_entropy',
                'sha256' => $sha,
                'width' => $width,
                'height' => $height,
                'size_bytes' => $bytesLen,
            ];
        }

        return [
            'accept' => true,
            'reason' => 'accepted',
            'sha256' => $sha,
            'width' => $width,
            'height' => $height,
            'size_bytes' => $bytesLen,
        ];
    }

    private function compressionRatio(string $bytes): ?float
    {
        // gzcompress can return false if zlib isn’t available; treat as unknown.
        $compressed = @gzcompress($bytes, 9);
        if (! is_string($compressed) || $compressed === '') {
            return null;
        }

        $raw = strlen($bytes);
        if ($raw <= 0) {
            return null;
        }

        return strlen($compressed) / $raw;
    }
}
