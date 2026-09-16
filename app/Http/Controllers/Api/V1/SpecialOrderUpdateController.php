<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateSpecialOrderRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderUpdateService;

final class SpecialOrderUpdateController extends Controller
{
    public function __construct(
        private readonly SpecialOrderUpdateService $update,
    ) {}

    public function __invoke(string $id, UpdateSpecialOrderRequest $request): SpecialOrderResource
    {
        $order = $this->update->update($id, $request->validated());

        return SpecialOrderResource::make($order);
    }
}
