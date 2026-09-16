<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderQueryService;

final class SpecialOrderShowController extends Controller
{
    public function __construct(
        private readonly SpecialOrderQueryService $orders,
    ) {}

    public function __invoke(string $id): SpecialOrderResource
    {
        return SpecialOrderResource::make($this->orders->findByUuidOrFail($id));
    }
}
