<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductSellingPriceHistoryQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductSellingPriceHistoryController extends Controller
{
    public function __construct(
        private readonly ProductSellingPriceHistoryQueryService $history,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);

        return response()->json([
            'data' => [
                'entries' => $this->history->listForProduct($id, $limit),
            ],
        ]);
    }
}
