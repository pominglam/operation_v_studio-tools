<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductBulkPlamodAssetRenameRequest;
use App\Services\Products\ProductsBulkRenameAssetsService;
use Illuminate\Http\JsonResponse;

final class ProductBulkPlamodAssetRenameController extends Controller
{
    public function __invoke(ProductBulkPlamodAssetRenameRequest $request, ProductsBulkRenameAssetsService $service): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids');

        $res = $service->queue($ids);
        if ($res->batchId === '') {
            return response()->json([
                'ok' => false,
                'error' => 'no_products',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'queued' => $res->queued,
            'batch_id' => $res->batchId,
        ], 202);
    }
}
