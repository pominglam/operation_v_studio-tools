<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductsIndexRequest;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\Exceptions\DuplicateSkuException;
use App\Services\Products\ProductCreateService;
use App\Services\Products\ProductsQueryService;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProductsController extends Controller
{
    public function __construct(
        private readonly ProductsQueryService $products,
        private readonly ProductCreateService $creator,
        private readonly ProductUpdateService $updater,
    ) {}

    public function index(ProductsIndexRequest $request): AnonymousResourceCollection
    {
        $perPage = (int) ($request->validated('per_page') ?? 25);
        $perPage = max(1, min($perPage, 500));

        /** @var string|null $search */
        $search = $request->validated('search');

        /** @var array<int, string> $types */
        $types = $request->validated('types') ?? [];

        /** @var array<int, string> $vendors */
        $vendors = $request->validated('vendors') ?? [];

        /** @var array<int, string> $missing */
        $missing = $request->validated('missing') ?? [];

        /** @var string|null $sortBy */
        $sortBy = $request->validated('sort_by');

        /** @var string $sortDir */
        $sortDir = $request->validated('sort_dir') ?? 'asc';

        return ProductResource::collection(
            $this->products->paginate($perPage, $search, $types, $vendors, $missing, $sortBy, $sortDir),
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
