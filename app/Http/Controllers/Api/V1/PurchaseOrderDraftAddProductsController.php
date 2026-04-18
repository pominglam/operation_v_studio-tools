<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrderDraftAddProductsRequest;
use App\Services\PurchaseOrders\PurchaseOrderDraftService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderDraftAddProductsController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDraftService $drafts,
    ) {}

    public function __invoke(PurchaseOrderDraftAddProductsRequest $request, string $id): JsonResponse
    {
        /** @var array<int, string> $skus */
        $skus = $request->validated('skus') ?? [];

        $result = $this->drafts->addProductsBySkus($id, $skus);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }
}
