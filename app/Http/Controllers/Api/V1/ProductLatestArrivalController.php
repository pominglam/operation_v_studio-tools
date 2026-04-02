<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductLatestArrivalRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductLatestArrivalController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductLatestArrivalRequest $request, string $id): JsonResponse
    {
        /** @var bool $latestArrival */
        $latestArrival = $request->validated('latest_arrival');

        $product = $this->updater->updateLatestArrival($id, $latestArrival);

        return ProductResource::make($product)->response();
    }
}

