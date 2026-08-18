<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodRestockReorderOverrideUpsertRequest;
use App\Services\Plamod\PlamodRestockReorderOverrideService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockReorderOverrideUpsertController extends Controller
{
    public function __invoke(
        string $sku,
        PlamodRestockReorderOverrideUpsertRequest $request,
        PlamodRestockReorderOverrideService $overrides,
    ): JsonResponse {
        try {
            $result = $overrides->upsert(
                $sku,
                $request->validated('reorder_qty'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $result]);
    }
}
