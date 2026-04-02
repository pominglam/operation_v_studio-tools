<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Inventory\EmployeeInventoryCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryCheckApplyController extends Controller
{
    public function __invoke(Request $request, string $id, EmployeeInventoryCountService $service): JsonResponse
    {
        $validated = $request->validate([
            'line_item_ids' => ['sometimes', 'array'],
            'line_item_ids.*' => ['integer', 'min:1'],
            'apply_quantity' => ['sometimes', 'boolean'],
            'apply_name' => ['sometimes', 'boolean'],
        ]);

        /** @var array<int, int>|null $lineIds */
        $lineIds = array_key_exists('line_item_ids', $validated)
            ? array_map(static fn ($v): int => (int) $v, (array) $validated['line_item_ids'])
            : null;

        return response()->json([
            'data' => $service->applySessionQuantities(
                $id,
                $lineIds,
                (bool) ($validated['apply_quantity'] ?? true),
                (bool) ($validated['apply_name'] ?? true),
            ),
        ]);
    }
}

