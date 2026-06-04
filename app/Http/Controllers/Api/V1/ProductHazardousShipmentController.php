<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductHazardousShipmentRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductHazardousShipmentController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductHazardousShipmentRequest $request, string $id): JsonResponse
    {
        /** @var bool $isHazardousShipment */
        $isHazardousShipment = $request->validated('is_hazardous_shipment');

        $product = $this->updater->updateHazardousShipment($id, $isHazardousShipment);

        return ProductResource::make($product)->response();
    }
}
