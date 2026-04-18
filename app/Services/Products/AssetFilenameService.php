<?php

declare(strict_types=1);

namespace App\Services\Products;

use Illuminate\Support\Str;

final class AssetFilenameService
{
    public function buildTitleSlug(?string $title): string
    {
        $t = trim((string) ($title ?? ''));
        if ($t === '') {
            return 'product-image';
        }

        $t = Str::ascii($t);
        $t = mb_strtolower($t);
        $t = preg_replace('/\s+/', '-', $t) ?? $t;

        // Keep only [a-z0-9_-]
        $t = preg_replace('/[^a-z0-9_-]+/', '-', $t) ?? $t;
        $t = preg_replace('/-+/', '-', $t) ?? $t;
        $t = preg_replace('/_+/', '_', $t) ?? $t;
        $t = trim($t, "-_\t\n\r\0\x0B");

        if ($t === '') {
            return 'product-image';
        }

        // Keep filenames manageable.
        return mb_substr($t, 0, 60);
    }

    public function buildSeoFilename(string $titleSlug, int $index, int $assetId, string $ext): string
    {
        $titleSlug = $this->buildTitleSlug($titleSlug);
        $indexPart = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        $ext = ltrim(mb_strtolower(trim($ext)), '.');
        if ($ext === '') {
            $ext = 'bin';
        }

        return "{$titleSlug}-{$indexPart}-{$assetId}.{$ext}";
    }
}
