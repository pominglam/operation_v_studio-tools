<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShopifySettingsUpdateRequest;
use App\Services\Shopify\Admin\ShopifySettingsService;
use Illuminate\Http\JsonResponse;

final class ShopifySettingsUpdateController extends Controller
{
    public function __invoke(ShopifySettingsUpdateRequest $request, ShopifySettingsService $service): JsonResponse
    {
        $hours = (int) $request->validated('order_reconcile_interval_hours');
        $service->setOrderReconcileIntervalHours($hours);

        return response()->json(['data' => $service->snapshot()]);
    }
}
