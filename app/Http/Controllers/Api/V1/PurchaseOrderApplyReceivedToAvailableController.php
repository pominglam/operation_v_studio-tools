<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderWorkflowPrepareInventoryException;
use App\Services\PurchaseOrders\PurchaseOrderApplyReceivedToAvailableService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowChecklistService;
use App\Services\PurchaseOrders\PurchaseOrderWorkflowVerifyService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderApplyReceivedToAvailableController extends Controller
{
    public function __invoke(
        string $id,
        PurchaseOrderApplyReceivedToAvailableService $service,
        PurchaseOrderWorkflowChecklistService $checklist,
        PurchaseOrderWorkflowVerifyService $verify,
    ): JsonResponse {
        try {
            $summary = $service->apply($id);
        } catch (PurchaseOrderWorkflowPrepareInventoryException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'issues' => $e->issues(),
            ], 422);
        }

        $checklist->update($id, [
            'update_product_available_with_shopify_current_inventory_quantity' => true,
        ]);
        $verification = $verify->verifyAndAutoCheck($id);

        return response()->json([
            'ok' => true,
            'data' => [
                'apply' => $summary,
                'steps' => $verification['steps'],
                'purchase_order' => PurchaseOrderResource::make($verification['purchase_order']),
            ],
        ]);
    }
}
