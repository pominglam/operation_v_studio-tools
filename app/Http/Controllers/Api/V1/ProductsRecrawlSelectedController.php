<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsRecrawlSelectedRequest;
use App\Services\Products\ProductsRecrawlSelectedService;
use Illuminate\Http\JsonResponse;

final class ProductsRecrawlSelectedController extends Controller
{
    public function __invoke(ProductsRecrawlSelectedRequest $request, ProductsRecrawlSelectedService $service): JsonResponse
    {
        /** @var array<int, string> $ids */
        $ids = (array) $request->validated('ids');
        /** @var array<int, string> $sources */
        $sources = (array) $request->validated('sources');

        $res = $service->recrawlSelected($ids, $sources);
        if ($res->batchId === '') {
            return response()->json([
                'ok' => false,
                'error' => 'no_products',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'queued' => $res->queued,
            'batch_id' => $res->batchId,
        ], 202);
    }
}
