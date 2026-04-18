<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PurchaseOrders\Exceptions\PurchaseOrderDeleteException;
use App\Services\PurchaseOrders\PurchaseOrderDeleteService;
use Illuminate\Http\JsonResponse;

final class PurchaseOrderDeleteController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderDeleteService $deleter,
    ) {}

    public function __invoke(string $id): JsonResponse
    {
        try {
            $this->deleter->delete($id);
        } catch (PurchaseOrderDeleteException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Purchase order deleted.']);
    }
}
