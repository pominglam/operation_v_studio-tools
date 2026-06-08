<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlamodPreorder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PlamodPreorderImageViewController extends Controller
{
    public function __invoke(string $sku): BinaryFileResponse
    {
        $sku = trim(rawurldecode($sku));
        if ($sku === '') {
            abort(404);
        }

        /** @var PlamodPreorder|null $row */
        $row = PlamodPreorder::query()->where('sku', '=', $sku)->first();
        if ($row === null || $row->image_storage_path === null) {
            abort(404);
        }

        $disk = Storage::disk('local');
        $path = (string) $row->image_storage_path;
        if (! $disk->exists($path)) {
            abort(404);
        }

        $abs = $disk->path($path);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };

        return response()->file($abs, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$this->safeFilename($sku, $ext).'"',
        ]);
    }

    private function safeFilename(string $sku, string $ext): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sku) ?: 'image';

        return $safe.'.'.$ext;
    }
}
