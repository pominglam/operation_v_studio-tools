<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpecialOrderCompetitorPricesRefreshRequest;
use App\Http\Resources\Api\V1\SpecialOrderResource;
use App\Services\SpecialOrders\SpecialOrderCompetitorPricesRefreshService;
use Illuminate\Http\JsonResponse;

final class SpecialOrderCompetitorPricesRefreshController extends Controller
{
    public function __construct(
        private readonly SpecialOrderCompetitorPricesRefreshService $refresh,
    ) {}

    public function __invoke(
        string $id,
        SpecialOrderCompetitorPricesRefreshRequest $request,
    ): JsonResponse {
        $scope = $request->validated()['scope'] ?? null;

        $order = $this->refresh->queueRefresh(
            $id,
            is_string($scope) ? $scope : null,
        );

        return SpecialOrderResource::make($order)
            ->response()
            ->setStatusCode(202);
    }
}
