<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductBulkPlamodAssetRenameRequest;
use App\Services\Products\PlamodAssetFilenameService;
use Illuminate\Http\JsonResponse;

final class ProductBulkPlamodAssetRenameController extends Controller
{
    public function __invoke(ProductBulkPlamodAssetRenameRequest $request, PlamodAssetFilenameService $service): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');

        $out = $service->bulkRename($ids);

        return response()->json([
            'ok' => true,
            'renamed_assets' => $out['renamed_assets'],
            'products' => $out['products'],
        ]);
    }
}






