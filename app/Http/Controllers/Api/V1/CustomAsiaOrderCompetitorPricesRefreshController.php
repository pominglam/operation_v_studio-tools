<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomAsiaOrderCompetitorPricesRefreshRequest;
use App\Http\Resources\Api\V1\CustomAsiaOrderResource;
use App\Services\CustomOrders\CustomAsiaOrderCompetitorPricesRefreshService;
use Illuminate\Http\JsonResponse;

final class CustomAsiaOrderCompetitorPricesRefreshController extends Controller
{
    public function __construct(
        private readonly CustomAsiaOrderCompetitorPricesRefreshService $refresh,
    ) {}

    public function __invoke(
        string $id,
        CustomAsiaOrderCompetitorPricesRefreshRequest $request,
    ): JsonResponse {
        $scope = $request->validated()['scope'] ?? null;

        $order = $this->refresh->queueRefresh(
            $id,
            is_string($scope) ? $scope : null,
        );

        return CustomAsiaOrderResource::make($order)
            ->response()
            ->setStatusCode(202);
    }
}
