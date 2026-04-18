<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductExternalAssetShopifyEnabledRequest;
use App\Services\Products\ProductExternalAssetShopifyPreferenceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class ProductExternalAssetShopifyEnabledController extends Controller
{
    public function __invoke(int $id, ProductExternalAssetShopifyEnabledRequest $request, ProductExternalAssetShopifyPreferenceService $service): JsonResponse
    {
        $enabled = (bool) $request->validated('shopify_enabled');

        try {
            $asset = $service->setShopifyEnabled($id, $enabled);
        } catch (ModelNotFoundException) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => (int) $asset->id,
                'shopify_enabled' => (bool) ($asset->shopify_enabled ?? true),
            ],
        ]);
    }
}
