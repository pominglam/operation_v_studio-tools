<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateCustomAsiaOrderRequest;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderUpdateService;

final class CustomAsiaOrderUpdateController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderUpdateService $update,
    ) {}

    public function __invoke(string $id, UpdateCustomAsiaOrderRequest $request): CustomAsiaOrderResource
    {
        $order = $this->update->update($id, $request->validated());

        return CustomAsiaOrderResource::make($order);
    }
}
