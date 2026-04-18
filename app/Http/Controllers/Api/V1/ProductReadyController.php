<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductReadyRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductReadyController extends Controller
{
    public function __construct(private readonly ProductUpdateService $updater) {}

    public function __invoke(UpdateProductReadyRequest $request, string $id): JsonResponse
    {
        /** @var bool $isReady */
        $isReady = (bool) $request->validated('is_ready');

        $product = $this->updater->updateReady($id, $isReady);

        return ProductResource::make($product)->response();
    }
}
