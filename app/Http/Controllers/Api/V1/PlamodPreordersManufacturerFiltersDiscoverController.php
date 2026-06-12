<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PlamodPreordersManufacturerFiltersDiscoverRequest;
use App\Services\Plamod\PlamodPreorderManufacturerFilterDiscoverJobService;
use Illuminate\Http\JsonResponse;

final class PlamodPreordersManufacturerFiltersDiscoverController extends Controller
{
    public function __invoke(
        PlamodPreordersManufacturerFiltersDiscoverRequest $request,
        PlamodPreorderManufacturerFilterDiscoverJobService $discoverJobs,
    ): JsonResponse {
        $validated = $request->validated();
        $jobId = isset($validated['job_id']) ? (string) $validated['job_id'] : '';

        $data = $jobId !== ''
            ? $discoverJobs->status($jobId)
            : $discoverJobs->start();

        return response()->json(['data' => $data]);
    }
}
