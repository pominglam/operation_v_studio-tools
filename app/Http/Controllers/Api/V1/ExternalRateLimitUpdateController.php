<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExternalRateLimitUpdateRequest;
use App\Services\Maintenance\ExternalRateLimitService;
use Illuminate\Http\JsonResponse;

final class ExternalRateLimitUpdateController extends Controller
{
    public function __invoke(ExternalRateLimitUpdateRequest $request, ExternalRateLimitService $service): JsonResponse
    {
        $hits = (int) $request->validated('hits_per_minute');
        $saved = $service->setHitsPerMinute($hits);

        return response()->json([
            'data' => [
                'hits_per_minute' => $saved,
            ],
        ]);
    }
}
