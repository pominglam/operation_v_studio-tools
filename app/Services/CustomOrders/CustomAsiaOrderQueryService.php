<?php

declare(strict_types=1);

namespace App\Services\CustomOrders;

use App\DAL\CustomOrders\CustomAsiaOrderRepository;
use App\Models\CustomAsiaOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CustomAsiaOrderQueryService
{
    public function __construct(
        private readonly CustomAsiaOrderRepository $orders,
        private readonly CustomAsiaOrderCompetitorPricesRefreshService $competitorPricesRefresh,
    ) {}

    /**
     * @param  array<int, string>  $contactMedia
     */
    public function paginate(
        int $perPage,
        string $sortBy,
        string $sortDir,
        ?string $search,
        array $contactMedia,
        ?string $quoteStatus,
        ?string $pricingStatus,
        ?string $lifecycleStatus,
    ): LengthAwarePaginator {
        return $this->orders->paginate($perPage, $sortBy, $sortDir, $search, $contactMedia, $quoteStatus, $pricingStatus, $lifecycleStatus);
    }

    public function findByUuidOrFail(string $uuid): CustomAsiaOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        return $this->competitorPricesRefresh->resumeIfStuck($order);
    }
}
