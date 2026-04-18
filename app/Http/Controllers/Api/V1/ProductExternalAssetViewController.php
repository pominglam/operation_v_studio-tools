<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ProductExternalAssetViewController extends Controller
{
    public function __invoke(int $id, ProductExternalAssetRepository $assets): BinaryFileResponse
    {
        $asset = $assets->findById($id);
        if ($asset === null) {
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
        ]);
    }
}
