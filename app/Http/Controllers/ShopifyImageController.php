<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Services\Shopify\ShopifyImageUrlSigner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ShopifyImageController extends Controller
{
    public function __invoke(
        int $id,
        Request $request,
        ProductExternalAssetRepository $assets,
        ShopifyImageUrlSigner $signer,
        ?int $expires = null,
        ?string $signature = null,
        ?string $filename = null,
    ): BinaryFileResponse
    {
        $valid = $request->hasValidSignature();
        if (! $valid && $expires !== null && $signature !== null) {
            $valid = $signer->isValid($id, $expires, $signature);
        }

        if (! $valid) {
            abort(404);
        }

        $asset = $assets->findById($id);
        if ($asset === null) {
            abort(404);
        }

        // Only serve images (defense-in-depth; URLs must be safe to expose publicly).
        $isImage = $asset->kind === 'image' || str_starts_with((string) ($asset->mime_type ?? ''), 'image/');
        if (! $isImage) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($asset->storage_path)) {
            abort(404);
        }

        $path = $disk->path($asset->storage_path);
        $mime = is_string($asset->mime_type) && $asset->mime_type !== '' ? $asset->mime_type : 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$asset->filename.'"',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}


