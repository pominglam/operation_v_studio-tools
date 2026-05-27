<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Admin\ShopifySettingsService;
use Illuminate\Http\JsonResponse;

final class ShopifySettingsShowController extends Controller
{
    public function __invoke(ShopifySettingsService $service): JsonResponse
    {
        return response()->json(['data' => $service->snapshot()]);
    }
}
