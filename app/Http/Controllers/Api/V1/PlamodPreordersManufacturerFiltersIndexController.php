<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Plamod\PlamodPreorderManufacturerFilterService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersManufacturerFiltersIndexController extends Controller
{
    public function __invoke(PlamodPreorderManufacturerFilterService $filters): JsonResponse
    {
        return response()->json(['data' => $filters->listGrouped(1)]);
    }
}
