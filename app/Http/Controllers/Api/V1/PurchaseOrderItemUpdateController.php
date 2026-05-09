<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderItemUpdateRequest;
use App\Http\Resources\Api\V1\PurchaseOrderItemResource;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderItemUpdateException;
use App\Services\PurchaseOrders\PurchaseOrderItemUpdateService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderItemUpdateController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderItemUpdateService $items,
    ) {}

    public function __invoke(PurchaseOrderItemUpdateRequest $request, int $id): JsonResponse|PurchaseOrderItemResource
    {
        /** @var array{unit_cost?:int|float|string|null, qty_ordered?:int|null, qty_shipped?:int|null, qty_received?:int|null} $v */
        $v = $request->validated();

        try {
            $item = $this->items->updateItem(
                purchaseOrderItemId: $id,
                hasUnitCost: array_key_exists('unit_cost', $v),
                unitCost: array_key_exists('unit_cost', $v) ? ($v['unit_cost'] !== null ? (string) $v['unit_cost'] : null) : null,
                hasQtyOrdered: array_key_exists('qty_ordered', $v),
                qtyOrdered: array_key_exists('qty_ordered', $v) ? $v['qty_ordered'] : null,
                hasQtyShipped: array_key_exists('qty_shipped', $v),
                qtyShipped: array_key_exists('qty_shipped', $v) ? $v['qty_shipped'] : null,
                hasQtyReceived: array_key_exists('qty_received', $v),
                qtyReceived: array_key_exists('qty_received', $v) ? $v['qty_received'] : null,
            );

            return PurchaseOrderItemResource::make($item);
        } catch (PurchaseOrderItemUpdateException $e) {
            $status = collect($e->issues)->contains(fn ($x) => ($x['kind'] ?? null) === 'qty_received_has_lots') ? 409 : 422;

            return response()->json(
                [
                    'message' => $e->getMessage(),
                    'issues' => $e->issues,
                ],
                $status,
            );
        }
    }
}
