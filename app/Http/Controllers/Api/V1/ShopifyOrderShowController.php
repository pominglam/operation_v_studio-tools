<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ShopifyOrderResource;
use App\Services\Shopify\Admin\Orders\ShopifyOrderQueryService;
use Illuminate\Http\JsonResponse;

final class ShopifyOrderShowController extends Controller
{
    public function __invoke(int $id, ShopifyOrderQueryService $service): JsonResponse
    {
        $order = $service->findById($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return ShopifyOrderResource::make($order)->response();
    }
}
