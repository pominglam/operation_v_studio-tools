<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Inventory\EmployeeInventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmployeeInventoryCountController extends Controller
{
    public function createSession(Request $request, EmployeeInventoryCountService $service): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $role = (string) ($request->attributes->get('external_access_role', 'employee') ?? 'employee');

        return response()->json([
            'data' => $service->createSession(
                array_key_exists('name', $validated) ? (is_string($validated['name']) ? $validated['name'] : null) : null,
                $role,
            ),
        ], 201);
    }

    public function showSession(string $id, EmployeeInventoryCountService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->sessionPayload($id),
        ]);
    }

    public function scan(Request $request, string $id, EmployeeInventoryCountService $service): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:128'],
        ]);

        return response()->json([
            'data' => $service->scanBarcode($id, (string) $validated['barcode']),
        ]);
    }

    public function updateLine(Request $request, string $id, int $lineId, EmployeeInventoryCountService $service): JsonResponse
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

        return response()->json([
            'data' => $service->updateLine(
                $id,
                $lineId,
                array_key_exists('quantity', $validated) ? (int) $validated['quantity'] : null,
                array_key_exists('product_name', $validated) ? (is_string($validated['product_name']) ? $validated['product_name'] : null) : null,
            ),
        ]);
    }

    public function removeLine(string $id, int $lineId, EmployeeInventoryCountService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->removeLine($id, $lineId),
        ]);
    }

    public function flagIssue(Request $request, string $id, EmployeeInventoryCountService $service): JsonResponse
    {
        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:128'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $service->flagBarcodeIssue(
                $id,
                (string) $validated['barcode'],
                array_key_exists('reason', $validated) ? (is_string($validated['reason']) ? $validated['reason'] : null) : null,
            ),
        ]);
    }
}

