<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductFilledRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductFilledController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductFilledRequest $request, string $id): JsonResponse
    {
        /** @var int|null $filled */
        $filled = $request->validated('filled');

        $product = $this->updater->updateFilled($id, $filled);

        return ProductResource::make($product)->response();
    }
}


