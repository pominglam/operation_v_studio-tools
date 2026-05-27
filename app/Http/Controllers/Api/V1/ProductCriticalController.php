<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductCriticalRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductCriticalController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductCriticalRequest $request, string $id): JsonResponse
    {
        /** @var bool $isCritical */
        $isCritical = $request->validated('is_critical');

        $product = $this->updater->updateCritical($id, $isCritical);

        return ProductResource::make($product)->response();
    }
}
