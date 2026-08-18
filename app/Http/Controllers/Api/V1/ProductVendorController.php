<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductVendorRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductVendorController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductVendorRequest $request, string $id): JsonResponse
    {
        $vendor = $request->validated('vendor');
        $normalized = is_string($vendor) ? trim($vendor) : null;
        $product = $this->updater->updateVendor($id, $normalized !== '' ? $normalized : null);

        return ProductResource::make($product)->response();
    }
}
