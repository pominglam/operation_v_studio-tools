<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Http\Controllers\Controller;
use App\Services\Products\ProductExternalAssetThumbnailService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProductExternalAssetThumbnailController extends Controller
{
    public function __invoke(
        int $id,
        ProductExternalAssetRepository $assets,
        ProductExternalAssetThumbnailService $thumbnails,
    ): BinaryFileResponse {
        $asset = $assets->findById($id);
        if ($asset === null) {
            abort(404);
        }

        $servePath = $thumbnails->resolveServePath($asset);
        if ($servePath === null) {
            abort(404);
        }

        return response()->file($servePath->absolutePath, [
            'Content-Type' => $servePath->mimeType,
            'Content-Disposition' => 'inline; filename="'.$servePath->filename.'"',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
