<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductMaintainRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductMaintainController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductMaintainRequest $request, string $id): JsonResponse
    {
        /** @var int|null $maintain */
        $maintain = $request->validated('maintain');

        $product = $this->updater->updateMaintain($id, $maintain);

        return ProductResource::make($product)->response();
    }
}
