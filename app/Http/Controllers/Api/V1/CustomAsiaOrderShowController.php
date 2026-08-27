<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderQueryService;

final class CustomAsiaOrderShowController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderQueryService $orders,
    ) {}

    public function __invoke(string $id): CustomAsiaOrderResource
    {
        return CustomAsiaOrderResource::make($this->orders->findByUuidOrFail($id));
    }
}
