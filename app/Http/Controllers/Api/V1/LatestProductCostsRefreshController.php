<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductLatestCostCacheService;
use Illuminate\Http\JsonResponse;

final class LatestProductCostsRefreshController extends Controller
{
    public function __construct(
        private readonly ProductLatestCostCacheService $latestCosts,
    ) {}

    public function __invoke(): JsonResponse
    {
        $res = $this->latestCosts->recomputeAll();

        return response()->json([
            'status' => 'ok',
            'matched' => $res['matched'],
            'updated' => $res['updated'],
        ]);
    }
}
