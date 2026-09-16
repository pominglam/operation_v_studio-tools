<?php

declare(strict_types=1);

namespace App\DAL\SpecialOrders;

use App\Models\SpecialOrder;
use App\Support\SpecialOrders\SpecialOrderIndexSort;
use App\Support\SpecialOrders\SpecialOrderLifecycleStatus;
use App\Support\SpecialOrders\SpecialOrderWorkflowStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentSpecialOrderRepository implements SpecialOrderRepository
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
    ): LengthAwarePaginator {
        $sortBy = SpecialOrderIndexSort::normalize($sortBy);
        $sortDir = SpecialOrderIndexSort::normalizeDir($sortDir);
        $lifecycleStatus = SpecialOrderLifecycleStatus::normalize($lifecycleStatus);

        $query = SpecialOrder::query();

        if ($workflowStatuses !== []) {
            SpecialOrderWorkflowStatus::applyFilter($query, $workflowStatuses);
        } else {
            if ($lifecycleStatus === SpecialOrderLifecycleStatus::ACTIVE) {
                $query->whereNull('rejected_at')->whereNull('customer_considering_at');
            } elseif ($lifecycleStatus === SpecialOrderLifecycleStatus::CONSIDERING) {
                $query->whereNull('rejected_at')->whereNotNull('customer_considering_at');
            } elseif ($lifecycleStatus === SpecialOrderLifecycleStatus::REJECTED) {
                $query->whereNotNull('rejected_at');
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
        }

        if (is_string($search) && trim($search) !== '') {
            self::applySearchFilter($query, $search);
        }

        if ($contactMedia !== []) {
            $query->whereIn('customer_contact_media', $contactMedia);
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

        if ($sortBy === 'balance') {
            return $query
                ->orderByRaw(
                    '(customer_price_cad * (100 - COALESCE(deposit_percent, 0)) / 100) '.$sortDir,
                )
                ->orderBy('id', 'desc')
                ->paginate($perPage);
        }

        return $query
            ->orderBy($column, $sortDir)
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * @param  array<int, string>  $contactMedia
     * @return array<string, int>
     */
    public function countByWorkflowStatus(?string $search, array $contactMedia): array
    {
        /** @var array<string, int> $counts */
        $counts = array_fill_keys(SpecialOrderWorkflowStatus::ALL_VALUES, 0);

        foreach (SpecialOrderWorkflowStatus::ALL_VALUES as $status) {
            $query = SpecialOrder::query();
            if (is_string($search) && trim($search) !== '') {
                self::applySearchFilter($query, $search);
            }
            if ($contactMedia !== []) {
                $query->whereIn('customer_contact_media', $contactMedia);
            }
            SpecialOrderWorkflowStatus::applyFilter($query, [$status]);
            $counts[$status] = $query->count();
        }

        return $counts;
    }

    private static function applySearchFilter(Builder $query, string $search): void
    {
        $s = '%'.trim($search).'%';
        $query->where(function (Builder $q) use ($s): void {
            $q->where('customer_contact_value', 'like', $s)
                ->orWhere('product_name', 'like', $s)
                ->orWhere('notes', 'like', $s);
        });
    }

    public function findByUuidOrFail(string $uuid): SpecialOrder
    {
        /** @var SpecialOrder $order */
        $order = SpecialOrder::query()->where('uuid', '=', $uuid)->firstOrFail();

        return $order;
    }

    public function create(array $attributes): SpecialOrder
    {
        /** @var SpecialOrder $order */
        $order = SpecialOrder::query()->create($attributes);

        return $order;
    }

    public function update(SpecialOrder $order, array $attributes): SpecialOrder
    {
        $order->fill($attributes);
        $order->save();

        return $order;
    }

    public function delete(SpecialOrder $order): void
    {
        $order->delete();
    }
}
