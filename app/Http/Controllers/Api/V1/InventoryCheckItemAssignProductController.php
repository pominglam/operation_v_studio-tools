<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryCheckItemAssignProductRequest;
use App\Services\Inventory\EmployeeInventoryCountService;
use Illuminate\Http\JsonResponse;

final class InventoryCheckItemAssignProductController extends Controller
{
    public function __invoke(
        InventoryCheckItemAssignProductRequest $request,
        string $id,
        int $lineId,
        EmployeeInventoryCountService $service,
    ): JsonResponse {
        /** @var string $productUuid */
        $productUuid = $request->validated('product_id');

        $service->assignLineToProduct($id, $lineId, $productUuid);

        return response()->json(['message' => 'Inventory check line assigned.']);
    }
}
