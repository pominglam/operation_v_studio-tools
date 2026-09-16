<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductUrgentRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductUrgentController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductUrgentRequest $request, string $id): JsonResponse
    {
        /** @var bool $isUrgent */
        $isUrgent = $request->validated('is_urgent');

        $product = $this->updater->updateUrgent($id, $isUrgent);

        return ProductResource::make($product)->response();
    }
}
