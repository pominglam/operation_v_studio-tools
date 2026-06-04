<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateProductShipmentMethodRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Products\ProductUpdateService;
use Illuminate\Http\JsonResponse;

final class ProductShipmentMethodController extends Controller
{
    public function __construct(
        private readonly ProductUpdateService $updater,
    ) {}

    public function __invoke(UpdateProductShipmentMethodRequest $request, string $id): JsonResponse
    {
        /** @var string|null $shipmentMethod */
        $shipmentMethod = $request->validated('shipment_method');

        $product = $this->updater->updateShipmentMethod($id, $shipmentMethod);

        return ProductResource::make($product)->response();
    }
}
