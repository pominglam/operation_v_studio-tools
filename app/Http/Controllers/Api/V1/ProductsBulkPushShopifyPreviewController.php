<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsBulkPushShopifyPreviewRequest;
use App\Services\Products\ProductsBulkPushShopifyPreviewService;
use Illuminate\Http\JsonResponse;

final class ProductsBulkPushShopifyPreviewController extends Controller
{
    public function __invoke(ProductsBulkPushShopifyPreviewRequest $request, ProductsBulkPushShopifyPreviewService $service): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = (array) $request->validated('ids');
        /** @var array<string, mixed> $pushOptionsPayload */
        $pushOptionsPayload = (array) $request->validated('push_options');
        $options = ShopifyProductPushOptionsDTO::fromArray($pushOptionsPayload);

        return response()->json([
            'ok' => true,
            'data' => $service->preview($ids, $options),
        ]);
    }
}
