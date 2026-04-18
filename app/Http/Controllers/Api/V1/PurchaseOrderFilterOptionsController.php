<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PurchaseOrders\PurchaseOrderQueryService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderFilterOptionsController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderQueryService $purchaseOrders,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'vendors' => $this->purchaseOrders->distinctVendors(),
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
