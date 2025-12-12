<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductMaintenanceService;
use Illuminate\Http\JsonResponse;

final class ProductMaintenanceController extends Controller
{
    public function __construct(
        private readonly ProductMaintenanceService $maintenance,
    ) {
    }

    public function flush(): JsonResponse
    {
        $this->maintenance->flushAll();

        return response()->json([
            'status' => 'ok',
        ]);
    }
}


