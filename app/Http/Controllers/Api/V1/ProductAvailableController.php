<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductAvailableRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductAvailableController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductAvailableRequest $request, string $id): JsonResponse
    {
        /** @var int|null $available */
        $available = $request->validated('available');

        $product = $this->updater->updateAvailable($id, $available);

        return ProductResource::make($product)->response();
    }
}
