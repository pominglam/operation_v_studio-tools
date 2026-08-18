<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodRestockDecisionUpsertRequest;
use App\Services\Plamod\PlamodRestockDecisionService;
use Illuminate\Http\JsonResponse;

final class PlamodRestockDecisionUpsertController extends Controller
{
    public function __invoke(
        string $sku,
        PlamodRestockDecisionUpsertRequest $request,
        PlamodRestockDecisionService $decisions,
    ): JsonResponse {
        try {
            $result = $decisions->upsert(
                $sku,
                $request->decisionStatus(),
                $request->validated('order_qty'),
                $request->validated('planned_maintain_qty'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'data' => $result]);
    }
}
