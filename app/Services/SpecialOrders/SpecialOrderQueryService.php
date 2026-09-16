<?php

declare(strict_types=1);

namespace App\Services\SpecialOrders;

use App\DAL\SpecialOrders\SpecialOrderRepository;
use App\Models\SpecialOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class SpecialOrderQueryService
{
    public function __construct(
        private readonly SpecialOrderRepository $orders,
        private readonly SpecialOrderCompetitorPricesRefreshService $competitorPricesRefresh,
    ) {}

    /**
     * @param  array<int, string>  $contactMedia
     * @param  array<int, string>  $workflowStatuses
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
        array $workflowStatuses = [],
    ): LengthAwarePaginator {
        return $this->orders->paginate(
            $perPage,
            $sortBy,
            $sortDir,
            $search,
            $contactMedia,
            $quoteStatus,
            $pricingStatus,
            $lifecycleStatus,
            $workflowStatuses,
        );
    }

    /**
     * @param  array<int, string>  $contactMedia
     * @return array<string, int>
     */
    public function workflowStatusCounts(?string $search, array $contactMedia): array
    {
        return $this->orders->countByWorkflowStatus($search, $contactMedia);
    }

    public function findByUuidOrFail(string $uuid): SpecialOrder
    {
        $order = $this->orders->findByUuidOrFail($uuid);

        return $this->competitorPricesRefresh->resumeIfStuck($order);
    }
}
