<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\ExternalRateLimitService;
use Illuminate\Http\JsonResponse;

final class ExternalRateLimitShowController extends Controller
{
    public function __invoke(ExternalRateLimitService $service): JsonResponse
    {
        return response()->json([
            'data' => [
                'hits_per_minute' => $service->getHitsPerMinute(),
            ],
        ]);
    }
}

