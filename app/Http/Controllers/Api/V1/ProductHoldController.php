<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductHoldRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use App\Support\Products\InvalidProductHoldQtyException;
use Illuminate\Http\JsonResponse;

final class ProductHoldController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductHoldRequest $request, string $id): JsonResponse
    {
        /** @var int|null $hold */
        $hold = $request->validated('hold');

        try {
            $product = $this->updater->updateHold($id, $hold);
        } catch (InvalidProductHoldQtyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return ProductResource::make($product)->response();
    }
}
