<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Inventory\EmployeeInventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class InventoryCheckItemUpdateController extends Controller
{
    public function __invoke(Request $request, string $id, int $lineId, EmployeeInventoryCountService $service): JsonResponse|Response
    {
        $validated = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'product_name' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);
        if (! array_key_exists('quantity', $validated) && ! array_key_exists('product_name', $validated)) {
            return response()->json([
                'message' => 'At least one of quantity or product_name is required.',
            ], 422);
        }

        $service->updateLine(
            $id,
            $lineId,
            array_key_exists('quantity', $validated) ? (int) $validated['quantity'] : null,
            array_key_exists('product_name', $validated) ? (is_string($validated['product_name']) ? $validated['product_name'] : null) : null,
            false,
        );

        return response()->noContent();
    }
}
