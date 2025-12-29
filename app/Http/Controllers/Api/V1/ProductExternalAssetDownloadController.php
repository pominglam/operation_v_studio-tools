<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DAL\Products\ProductExternalAssetRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

final class ProductExternalAssetDownloadController extends Controller
{
    public function __invoke(int $id, ProductExternalAssetRepository $assets): Response
    {
        $asset = $assets->findById($id);
        if ($asset === null) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($asset->storage_path)) {
            abort(404);
        }

        return $disk->download($asset->storage_path, $asset->filename);
    }
}


