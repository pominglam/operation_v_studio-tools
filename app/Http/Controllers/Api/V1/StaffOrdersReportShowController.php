<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StaffOrdersReportShowRequest;
use App\Services\Shopify\Admin\Orders\ShopifyStaffOrdersMonthlyReportService;
use Illuminate\Http\JsonResponse;

final class StaffOrdersReportShowController extends Controller
{
    public function __invoke(
        StaffOrdersReportShowRequest $request,
        ShopifyStaffOrdersMonthlyReportService $service,
    ): JsonResponse {
        return response()->json([
            'data' => $service->reportForMonth($request->month()),
        ]);
    }
}
