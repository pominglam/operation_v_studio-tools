<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyImageServeService;
use App\Services\Shopify\ShopifyImageUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ShopifyImageController extends Controller
{
    public function __invoke(
        int $id,
        Request $request,
        ShopifyImageServeService $images,
        ShopifyImageUrlSigner $signer,
        ?int $expires = null,
        ?string $signature = null,
        ?string $filename = null,
    ): BinaryFileResponse {
        $valid = $request->hasValidSignature();
        if (! $valid && $expires !== null && $signature !== null) {
            $valid = $signer->isValid($id, $expires, $signature);
        }

        if (! $valid) {
            abort(404);
        }

        $resolved = $images->resolve($id, $filename);
        if ($resolved === null) {
            abort(404);
        }
        $asset = $resolved['asset'];
        $storagePath = $resolved['storage_path'];

        $disk = Storage::disk('local');
        $path = $disk->path($storagePath);
        if (! is_file($path) || ! is_readable($path)) {
            abort(404);
        }
        $mime = is_string($asset->mime_type) && $asset->mime_type !== '' ? $asset->mime_type : 'application/octet-stream';

        try {
            return response()->file($path, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.$asset->filename.'"',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Throwable) {
            abort(404);
        }
    }
}
