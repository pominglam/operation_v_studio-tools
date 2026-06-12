<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodPreordersManufacturerFiltersUpdateRequest;
use App\Services\Plamod\PlamodPreorderManufacturerFilterService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersManufacturerFiltersUpdateController extends Controller
{
    public function __invoke(
        PlamodPreordersManufacturerFiltersUpdateRequest $request,
        PlamodPreorderManufacturerFilterService $filters,
    ): JsonResponse {
        /** @var array<int, array{id: int, decision: string}> $updates */
        $updates = $request->validated('updates');
        $filters->updateDecisions($updates);

        return response()->json(['data' => $filters->listGrouped(1)]);
    }
}
