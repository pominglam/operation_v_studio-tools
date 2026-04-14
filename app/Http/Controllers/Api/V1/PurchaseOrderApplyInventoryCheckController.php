<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderApplyInventoryCheckRequest;
use App\Services\PurchaseOrders\PurchaseOrderApplyInventoryCheckService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderApplyInventoryCheckController extends Controller
{
    public function __invoke(
        PurchaseOrderApplyInventoryCheckRequest $request,
        string $id,
        PurchaseOrderApplyInventoryCheckService $service,
    ): JsonResponse {
        /** @var array{inventory_check_id: string} $v */
        $v = $request->validated();

        return response()->json([
            'ok' => true,
            'data' => $service->apply($id, $v['inventory_check_id']),
        ]);
    }
}
