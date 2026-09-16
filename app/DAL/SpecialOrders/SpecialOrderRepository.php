<?php

declare(strict_types=1);

namespace App\DAL\SpecialOrders;

use App\Models\SpecialOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SpecialOrderRepository
{
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
    ): LengthAwarePaginator;

    /**
     * @param  array<int, string>  $contactMedia
     * @return array<string, int>
     */
    public function countByWorkflowStatus(?string $search, array $contactMedia): array;

    public function findByUuidOrFail(string $uuid): SpecialOrder;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SpecialOrder;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(SpecialOrder $order, array $attributes): SpecialOrder;

    public function delete(SpecialOrder $order): void;
}
