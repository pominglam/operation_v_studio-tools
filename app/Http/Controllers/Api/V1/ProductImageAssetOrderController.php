<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductImageAssetOrderRequest;
use App\Services\Products\ProductImageAssetOrderService;
use Illuminate\Http\JsonResponse;

final class ProductImageAssetOrderController extends Controller
{
    public function __invoke(string $id, ProductImageAssetOrderRequest $request, ProductImageAssetOrderService $service): JsonResponse
    {
        /** @var array<int, int> $assetIds */
        $assetIds = $request->validated('asset_ids');

        $service->reorderImageAssets($id, $assetIds);

        return response()->json(['ok' => true]);
    }
}
