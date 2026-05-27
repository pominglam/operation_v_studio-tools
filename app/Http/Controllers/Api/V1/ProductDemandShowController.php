<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductDemandShowRequest;
use App\Models\Product;
use App\Services\Shopify\Admin\Demand\ProductDemandRollupService;
use Illuminate\Http\JsonResponse;

final class ProductDemandShowController extends Controller
{
    public function __invoke(string $id, ProductDemandShowRequest $request, ProductDemandRollupService $service): JsonResponse
    {
        /** @var Product|null $product */
        $product = Product::query()->where('uuid', $id)->first();
        if ($product === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'data' => $service->detailForProduct(
                $product,
                $request->linesPage(),
                $request->linesPerPage(),
            ),
        ]);
    }
}
