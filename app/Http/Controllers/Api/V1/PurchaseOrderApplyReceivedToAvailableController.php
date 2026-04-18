<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PurchaseOrders\PurchaseOrderApplyReceivedToAvailableService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderApplyReceivedToAvailableController extends Controller
{
    public function __invoke(string $id, PurchaseOrderApplyReceivedToAvailableService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $service->apply($id),
        ]);
    }
}
