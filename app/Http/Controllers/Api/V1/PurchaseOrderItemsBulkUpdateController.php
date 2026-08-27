<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderItemsBulkUpdateRequest;
use App\Http\Resources\Api\V1\PurchaseOrderResource;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderItemUpdateException;
use App\Services\PurchaseOrders\PurchaseOrderItemUpdateService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderItemsBulkUpdateController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderItemUpdateService $items,
    ) {}

    public function __invoke(PurchaseOrderItemsBulkUpdateRequest $request, string $id): JsonResponse|PurchaseOrderResource
    {
        /** @var array<string, mixed> $v */
        $v = $request->validated();

        try {
            if (array_key_exists('ids', $v) && array_key_exists('changes', $v)) {
                /** @var array<int, int> $ids */
                $ids = $v['ids'];
                /** @var array<string, mixed> $changes */
                $changes = $v['changes'];

                $po = $this->items->bulkUpdateSelected(
                    purchaseOrderUuid: $id,
                    itemIds: array_map('intval', $ids),
                    changes: $changes,
                );
            } else {
                // Back-compat bulk mode (previous UI)
                $qtyAll = array_key_exists('qty_shipped_all', $v) ? ($v['qty_shipped_all'] !== null ? (int) $v['qty_shipped_all'] : null) : null;
                $setAllToOrdered = (bool) ($v['set_all_to_ordered'] ?? false);
                $po = $this->items->bulkUpdateQtyShipped(
                    purchaseOrderUuid: $id,
                    qtyShippedAll: $qtyAll,
                    setAllToOrdered: $setAllToOrdered,
                );
            }

            return PurchaseOrderResource::make($po);
        } catch (PurchaseOrderItemUpdateException $e) {
            $status = collect($e->issues)->contains(
                fn ($x) => in_array(($x['kind'] ?? null), ['qty_received_has_lots', 'receipt_quantities_have_lots'], true),
            ) ? 409 : 422;

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
