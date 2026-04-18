<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderDraftCreateFromProductsRequest;
use App\Services\PurchaseOrders\PurchaseOrderDraftService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class PurchaseOrderDraftCreateFromProductsController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDraftService $drafts,
    ) {}

    public function __invoke(PurchaseOrderDraftCreateFromProductsRequest $request): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = $request->validated('ids') ?? [];

        try {
            $result = $this->drafts->createDraftFromProductUuids($ids);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }
}
