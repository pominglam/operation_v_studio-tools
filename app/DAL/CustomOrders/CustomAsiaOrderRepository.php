<?php

declare(strict_types=1);

namespace App\DAL\CustomOrders;

use App\Models\CustomAsiaOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomAsiaOrderRepository
{
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
    ): LengthAwarePaginator;

    public function findByUuidOrFail(string $uuid): CustomAsiaOrder;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): CustomAsiaOrder;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(CustomAsiaOrder $order, array $attributes): CustomAsiaOrder;

    public function delete(CustomAsiaOrder $order): void;
}
