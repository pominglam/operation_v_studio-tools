<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSpecialOrderRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderCreateService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderStoreController extends Controller
{
    public function __construct(
        private readonly SpecialOrderCreateService $create,
    ) {}

    public function __invoke(StoreSpecialOrderRequest $request): JsonResponse
    {
        $order = $this->create->create($request->validated());

        return SpecialOrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }
}
