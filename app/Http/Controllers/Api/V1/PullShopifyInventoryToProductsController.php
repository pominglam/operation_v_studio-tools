<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\ShopifyMaintenanceDispatchService;
use Illuminate\Http\JsonResponse;

final class PullShopifyInventoryToProductsController extends Controller
{
    public function __invoke(ShopifyMaintenanceDispatchService $dispatch): JsonResponse
    {
        $dispatch->queueInventoryPull();

        return response()->json(['ok' => true, 'message' => 'Shopify inventory pull queued.']);
    }
}
