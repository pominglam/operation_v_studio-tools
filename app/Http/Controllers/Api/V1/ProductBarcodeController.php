<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductBarcodeRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductBarcodeController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductBarcodeRequest $request, string $id): JsonResponse
    {
        /** @var string|null $barcode */
        $barcode = $request->validated('barcode');
        $barcode = $barcode !== null ? trim($barcode) : null;
        if ($barcode === '') {
            $barcode = null;
        }

        $product = $this->updater->updateBarcode($id, $barcode);

        return ProductResource::make($product)->response();
    }
}


