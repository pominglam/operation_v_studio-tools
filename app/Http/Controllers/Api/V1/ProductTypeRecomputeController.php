<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductTypeRecomputeService;
use Illuminate\Http\JsonResponse;

final class ProductTypeRecomputeController extends Controller
{
    public function __construct(
        private readonly ProductTypeRecomputeService $recompute,
    ) {}

    public function __invoke(): JsonResponse
    {
        $updated = $this->recompute->recomputeAllTypes();

        return response()->json([
            'updated' => $updated,
        ]);
    }
}


