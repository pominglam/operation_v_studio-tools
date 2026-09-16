<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductExternalAssetShopifyEnabledBulkRequest;
use App\Services\Products\ProductExternalAssetShopifyPreferenceService;
use Illuminate\Http\JsonResponse;

final class ProductExternalAssetShopifyEnabledBulkController extends Controller
{
    public function __invoke(
        string $id,
        ProductExternalAssetShopifyEnabledBulkRequest $request,
        ProductExternalAssetShopifyPreferenceService $service,
    ): JsonResponse {
        /** @var array<int, int> $ids */
        $ids = array_map(static fn (mixed $value): int => (int) $value, $request->validated('ids'));
        $updated = $service->setShopifyEnabledForProduct(
            $id,
            $ids,
            (bool) $request->validated('shopify_enabled'),
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }
}
