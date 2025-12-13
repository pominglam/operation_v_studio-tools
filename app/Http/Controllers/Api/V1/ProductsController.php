<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\Products\ProductCreateService;
use App\Services\Products\ProductUpdateService;
use App\Services\Products\ProductsQueryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductsController extends Controller
{
    public function __construct(
        private readonly ProductsQueryService $products,
        private readonly ProductCreateService $creator,
        private readonly ProductUpdateService $updater,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min($perPage, 100));

        return ProductResource::collection(
            $this->products->paginate($perPage),
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        try {
            $product = $this->creator->create($request->validated());

            return ProductResource::make($product)->response()->setStatusCode(201);
        } catch (DuplicateSkuException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        try {
            $product = $this->updater->update($id, $request->validated());

            return ProductResource::make($product)->response();
        } catch (DuplicateSkuException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}


