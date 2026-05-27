<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Shopify\ShopifyAdminConfigurationException;
use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\ShopifyMaintenanceDispatchService;
use Illuminate\Http\JsonResponse;

final class ShopifyOrderHistoricalBackfillController extends Controller
{
    public function __invoke(ShopifyMaintenanceDispatchService $dispatch): JsonResponse
    {
        try {
            $dispatch->queueHistoricalBackfill();
        } catch (ShopifyAdminConfigurationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => 'Historical order backfill queued.']);
    }
}
