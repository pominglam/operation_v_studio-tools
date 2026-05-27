<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductDiscontinueRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductDiscontinueController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductDiscontinueRequest $request, string $id): JsonResponse
    {
        /** @var bool $isDiscontinued */
        $isDiscontinued = $request->validated('is_discontinued');

        $product = $this->updater->updateDiscontinued($id, $isDiscontinued);

        return ProductResource::make($product)->response();
    }
}
