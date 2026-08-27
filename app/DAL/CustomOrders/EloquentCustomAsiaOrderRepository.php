<?php

declare(strict_types=1);

namespace App\DAL\CustomOrders;

use App\Models\CustomAsiaOrder;
use App\Support\CustomOrders\CustomAsiaOrderIndexSort;
use App\Support\CustomOrders\CustomAsiaOrderLifecycleStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentCustomAsiaOrderRepository implements CustomAsiaOrderRepository
{
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
        $sortBy = CustomAsiaOrderIndexSort::normalize($sortBy);
        $sortDir = CustomAsiaOrderIndexSort::normalizeDir($sortDir);
        $lifecycleStatus = CustomAsiaOrderLifecycleStatus::normalize($lifecycleStatus);

        $query = CustomAsiaOrder::query();

        if ($lifecycleStatus === CustomAsiaOrderLifecycleStatus::ACTIVE) {
            $query->whereNull('rejected_at');
        } elseif ($lifecycleStatus === CustomAsiaOrderLifecycleStatus::REJECTED) {
            $query->whereNotNull('rejected_at');
        }

        if (is_string($search) && trim($search) !== '') {
            $s = '%'.trim($search).'%';
            $query->where(function (Builder $q) use ($s): void {
                $q->where('customer_contact_value', 'like', $s)
                    ->orWhere('product_name', 'like', $s)
                    ->orWhere('notes', 'like', $s);
            });
        }

        if ($contactMedia !== []) {
            $query->whereIn('customer_contact_media', $contactMedia);
        }

        if ($quoteStatus === 'quoted') {
            $query->whereNotNull('landed_cost_cad')->whereNotNull('receive_delay_days');
        } elseif ($quoteStatus === 'pending') {
            $query->where(function (Builder $q): void {
                $q->whereNull('landed_cost_cad')->orWhereNull('receive_delay_days');
            });
        }

        if ($pricingStatus === 'priced') {
            $query->whereNotNull('customer_price_cad')->whereNotNull('deposit_percent');
        } elseif ($pricingStatus === 'pending') {
            $query->where(function (Builder $q): void {
                $q->whereNull('customer_price_cad')->orWhereNull('deposit_percent');
            });
        }

        $column = match ($sortBy) {
            'updated' => 'updated_at',
            'contact' => 'customer_contact_value',
            'product_name' => 'product_name',
            'media' => 'customer_contact_media',
            'landed' => 'landed_cost_cad',
            'receive_delay' => 'receive_delay_days',
            'product_cost' => 'product_cost_amount',
            'shipping_cost' => 'shipping_cost_amount',
            'customer_price' => 'customer_price_cad',
            'deposit' => 'deposit_percent',
            'eta' => 'estimated_arrival_at',
            default => 'created_at',
        };

        return $query
            ->orderBy($column, $sortDir)
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    public function findByUuidOrFail(string $uuid): CustomAsiaOrder
    {
        /** @var CustomAsiaOrder $order */
        $order = CustomAsiaOrder::query()->where('uuid', '=', $uuid)->firstOrFail();

        return $order;
    }

    public function create(array $attributes): CustomAsiaOrder
    {
        /** @var CustomAsiaOrder $order */
        $order = CustomAsiaOrder::query()->create($attributes);

        return $order;
    }

    public function update(CustomAsiaOrder $order, array $attributes): CustomAsiaOrder
    {
        $order->fill($attributes);
        $order->save();

        return $order;
    }

    public function delete(CustomAsiaOrder $order): void
    {
        $order->delete();
    }
}
