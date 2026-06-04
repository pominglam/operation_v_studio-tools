<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ClearStaleLatestArrivalService;
use Illuminate\Http\JsonResponse;

final class ClearStaleLatestArrivalController extends Controller
{
    public function __invoke(ClearStaleLatestArrivalService $service): JsonResponse
    {
        $summary = $service->clear();

        return response()->json([
            'ok' => true,
            'data' => $summary,
        ]);
    }
}
