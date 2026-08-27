<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCustomAsiaOrderRequest;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderCreateService;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderStoreController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderCreateService $create,
    ) {}

    public function __invoke(StoreCustomAsiaOrderRequest $request): JsonResponse
    {
        $order = $this->create->create($request->validated());

        return CustomAsiaOrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }
}
