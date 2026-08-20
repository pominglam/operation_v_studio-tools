<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\InventoryByMainTypeReportService;
use Illuminate\Http\JsonResponse;

final class InventoryByMainTypeReportShowController extends Controller
{
    public function __invoke(InventoryByMainTypeReportService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->report(),
        ]);
    }
}
