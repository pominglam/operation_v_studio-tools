<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Shopify\ShopifyProductPushOptionsDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsBulkPushShopifySelectedRequest;
use App\Services\Products\ProductsBulkPushShopifyService;
use Illuminate\Http\JsonResponse;

final class ProductsBulkPushShopifySelectedController extends Controller
{
    public function __invoke(ProductsBulkPushShopifySelectedRequest $request, ProductsBulkPushShopifyService $service): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = (array) $request->validated('ids');
        /** @var array<string, mixed> $pushOptionsPayload */
        $pushOptionsPayload = (array) $request->validated('push_options');
        $options = ShopifyProductPushOptionsDTO::fromArray($pushOptionsPayload);

        $res = $service->pushSelected($ids, $options);
        if ($res->batchId === '') {
            return response()->json([
                'ok' => false,
                'error' => 'no_eligible_products',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'queued' => $res->queued,
            'batch_id' => $res->batchId,
        ], 202);
    }
}
