<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\ShopifyMaintenanceDispatchService;
use Illuminate\Http\JsonResponse;

final class RebuildProductDemandRollupsController extends Controller
{
    public function __invoke(ShopifyMaintenanceDispatchService $dispatch): JsonResponse
    {
        $dispatch->queueDemandRebuild();

        return response()->json(['ok' => true, 'message' => 'Demand rollup rebuild queued.']);
    }
}
