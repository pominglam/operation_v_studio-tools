<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowVerifyService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderWorkflowVerifyController extends Controller
{
    public function __invoke(string $id, PurchaseOrderWorkflowVerifyService $service): JsonResponse
    {
        $result = $service->verifyAndAutoCheck($id);

        return response()->json([
            'ok' => true,
            'data' => [
                'steps' => $result['steps'],
                'purchase_order' => PurchaseOrderResource::make($result['purchase_order']),
            ],
        ]);
    }
}
