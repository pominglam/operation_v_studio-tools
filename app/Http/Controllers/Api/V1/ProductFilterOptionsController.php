<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductsQueryService;
use Illuminate\Http\JsonResponse;

final class ProductFilterOptionsController extends Controller
{
    public function __construct(
        private readonly ProductsQueryService $products,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'types' => $this->products->distinctTypes(),
            ],
        ]);
    }
}
