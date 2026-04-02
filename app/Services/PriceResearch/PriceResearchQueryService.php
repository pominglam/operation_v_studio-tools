<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PriceResearchQueryService
{
    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $vendors
     * @param  array<int, string>  $siteKeys
     * @return array<int, string> Product UUIDs
     */
    public function productUuidsForRunFilters(
        ?string $status = null,
        array $types = [],
        array $vendors = [],
        ?string $quoteStatus = null,
        array $siteKeys = [],
    ): array
    {
        $status = $status !== null ? trim($status) : null;
        if ($status === '' || $status === null) {
            $status = 'any';
        }

        $quoteStatus = $quoteStatus !== null ? trim($quoteStatus) : null;
        if ($quoteStatus === '' || $quoteStatus === null) {
            $quoteStatus = 'any';
        }

        $vendors = array_values(array_unique(array_filter(array_map('trim', $vendors), static fn (string $v): bool => $v !== '')));
        $types = array_values(array_unique(array_filter(array_map('trim', $types), static fn (string $v): bool => $v !== '')));
        $siteKeys = array_values(array_unique(array_filter(array_map('trim', $siteKeys), static fn (string $v): bool => $v !== '')));

        $q = Product::query()->select('uuid');

        if ($vendors !== []) {
            $q->whereIn('vendor', $vendors);
        }
        if ($types !== []) {
            $q->whereIn('type', $types);
        }

        if ($status === 'fresh') {
            $q->whereNotNull('price_researched_at')
                ->where('price_researched_at', '>=', now()->subDays(max(1, (int) config('price_research.ttl_days', 14))));
        } elseif ($status === 'expired') {
            $q->where(function ($sub): void {
                $sub->whereNull('price_researched_at')
                    ->orWhere('price_researched_at', '<', now()->subDays(max(1, (int) config('price_research.ttl_days', 14))));
            });
        }

        if ($quoteStatus !== 'any') {
            $q->whereHas('priceQuotes', function ($qq) use ($siteKeys, $quoteStatus): void {
                if ($siteKeys !== []) {
                    $qq->whereIn('site_key', $siteKeys);
                }
                $qq->where('status', $quoteStatus);
            });
        }

        /** @var array<int, string> $uuids */
        $uuids = $q->orderBy('id')->pluck('uuid')->values()->all();

        return $uuids;
    }

    /**
     * @param  array<int, string>  $freshness
     * @param  array<int, string>  $quoteSites
     * @param  array<int, string>  $quoteStatuses
     * @param  array<int, string>  $quoteAvailabilities
     */
    public function paginateProductsWithQuotes(
        int $perPage,
        ?string $search = null,
        ?string $sortBy = null,
        string $sortDir = 'desc',
        ?string $sellingPrice = null,
        ?string $shippingPerUnit = null,
        ?string $barcode = null,
        array $purchaseOrderUuids = [],
        array $vendors = [],
        array $freshness = [],
        array $types = [],
        array $quoteSites = [],
        array $quoteStatuses = [],
        array $quoteAvailabilities = [],
    ): LengthAwarePaginator {
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';
        $sortBy = $sortBy !== null ? trim($sortBy) : null;

        $sortMap = [
            'sku' => 'sku',
            'description' => 'description',
            'price_researched_at' => 'price_researched_at',
            'filled' => 'filled_qty',
            'available' => 'available_qty',
            'cost' => 'latest_unit_cost',
            'selling_price' => 'sps.selling_price',
        ];
        $sortColumn = $sortBy !== null && array_key_exists($sortBy, $sortMap) ? $sortMap[$sortBy] : 'price_researched_at';

        $q = Product::query()
            ->with(['priceQuotes' => function ($q): void {
                $q->orderBy('site_key');
            }])
            ->with('sellingPrice')
            ->leftJoin('product_selling_prices as sps', 'sps.product_id', '=', 'products.id')
            ->select('products.*')
            ->addSelect([
                // Total cost across all PO lines for this product (sum of unit_cost * qty).
                // Uses received qty when present (>0), otherwise ordered qty.
                DB::raw("(
                    select sum(
                        coalesce(poi.unit_cost, 0) *
                        (case
                            when coalesce(poi.qty_received, 0) > 0 then poi.qty_received
                            else coalesce(poi.qty_ordered, 0)
                        end)
                    )
                    from purchase_order_items poi
                    where poi.product_id = products.id and poi.unit_cost is not null
                ) as po_total_cost"),
                // Price Research table should show latest PO costs (unit/ship/landed) to match the PO Lines drawer.
                // Ranges (min/max) still come from inventory lots to show price spread.
                DB::raw("(
                    select poi.unit_cost
                    from purchase_order_items poi
                    join purchase_orders po on po.id = poi.purchase_order_id
                    where poi.product_id = products.id and poi.unit_cost is not null
                    order by po.created_at desc, po.id desc, poi.id desc
                    limit 1
                ) as latest_unit_cost"),
                DB::raw("(
                    select
                        case
                            when coalesce(po.shipping_total,0) = 0 then null
                            else
                                coalesce(po.shipping_total,0) /
                                (nullif(
                                    case
                                        when po.received_date is not null then (select sum(coalesce(poi2.qty_received,0)) from purchase_order_items poi2 where poi2.purchase_order_id = po.id)
                                        else (select sum(coalesce(poi2.qty_ordered,0)) from purchase_order_items poi2 where poi2.purchase_order_id = po.id)
                                    end
                                ,0) * 1.0)
                        end
                    from purchase_order_items poi
                    join purchase_orders po on po.id = poi.purchase_order_id
                    where poi.product_id = products.id
                    order by po.created_at desc, po.id desc, poi.id desc
                    limit 1
                ) as latest_shipping_per_unit"),
                DB::raw("(
                    select
                        case
                            when coalesce(po.surcharge_total,0) = 0 then null
                            else
                                coalesce(po.surcharge_total,0) /
                                (nullif(
                                    case
                                        when po.received_date is not null then (select sum(coalesce(poi2.qty_received,0)) from purchase_order_items poi2 where poi2.purchase_order_id = po.id)
                                        else (select sum(coalesce(poi2.qty_ordered,0)) from purchase_order_items poi2 where poi2.purchase_order_id = po.id)
                                    end
                                ,0) * 1.0)
                        end
                    from purchase_order_items poi
                    join purchase_orders po on po.id = poi.purchase_order_id
                    where poi.product_id = products.id
                    order by po.created_at desc, po.id desc, poi.id desc
                    limit 1
                ) as latest_surcharge_per_unit"),
                DB::raw("(select min(il.unit_cost) from inventory_lots il where il.product_id = products.id and il.source_type <> 'negative_balance' and il.unit_cost is not null) as min_unit_cost"),
                DB::raw("(select max(il.unit_cost) from inventory_lots il where il.product_id = products.id and il.source_type <> 'negative_balance' and il.unit_cost is not null) as max_unit_cost"),
                DB::raw("(select min(coalesce(il.unit_cost,0) + coalesce(il.shipping_per_unit,0)) from inventory_lots il where il.product_id = products.id and il.source_type <> 'negative_balance' and il.unit_cost is not null) as min_landed_cost"),
                DB::raw("(select max(coalesce(il.unit_cost,0) + coalesce(il.shipping_per_unit,0)) from inventory_lots il where il.product_id = products.id and il.source_type <> 'negative_balance' and il.unit_cost is not null) as max_landed_cost"),
            ]);

        $purchaseOrderUuids = array_values(array_unique(array_filter(array_map(
            static fn (string $v): string => trim($v),
            $purchaseOrderUuids,
        ), static fn (string $v): bool => $v !== '')));
        if ($purchaseOrderUuids !== []) {
            $q->whereExists(function ($sub) use ($purchaseOrderUuids): void {
                $sub->select(DB::raw('1'))
                    ->from('purchase_order_items as poi')
                    ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                    ->whereColumn('poi.product_id', 'products.id')
                    ->whereIn('po.uuid', $purchaseOrderUuids);
            });
        }

        // Note: avoid COALESCE(x) with 1 arg (SQLite errors); this expression already yields NULL when missing.
        $latestShippingPerUnitExpr = "(
            select
                case
                    when coalesce(po.shipping_total,0) = 0 then null
                    else
                        coalesce(po.shipping_total,0) /
                        (nullif(
                            case
                                when po.received_date is not null then (select sum(coalesce(poi2.qty_received,0)) from purchase_order_items poi2 where poi2.purchase_order_id = po.id)
                                else (select sum(coalesce(poi2.qty_ordered,0)) from purchase_order_items poi2 where poi2.purchase_order_id = po.id)
                            end
                        ,0) * 1.0)
                end
            from purchase_order_items poi
            join purchase_orders po on po.id = poi.purchase_order_id
            where poi.product_id = products.id
            order by po.created_at desc, po.id desc, poi.id desc
            limit 1
        )";

        $sellingPrice = $sellingPrice !== null ? trim($sellingPrice) : null;
        if ($sellingPrice === 'set') {
            $q->whereHas('sellingPrice', function ($sp): void {
                $sp->whereNotNull('selling_price');
            });
        } elseif ($sellingPrice === 'missing') {
            $q->where(function ($sub): void {
                $sub->whereDoesntHave('sellingPrice')
                    ->orWhereHas('sellingPrice', function ($sp): void {
                        $sp->whereNull('selling_price');
                    });
            });
        }

        $shippingPerUnit = $shippingPerUnit !== null ? trim($shippingPerUnit) : null;
        if ($shippingPerUnit === 'set') {
            // Filter against the computed expression to support SQLite (tests) + MySQL (prod).
            $q->whereRaw("({$latestShippingPerUnitExpr}) > 0");
        } elseif ($shippingPerUnit === 'missing') {
            // Important: wrap OR in parentheses so later AND filters (vendors/types/etc) don't change the logic.
            $q->whereRaw("(({$latestShippingPerUnitExpr}) is null or ({$latestShippingPerUnitExpr}) = 0)");
        }

        $barcode = $barcode !== null ? trim($barcode) : null;
        if ($barcode === 'set') {
            $q->whereNotNull('barcode')->where('barcode', '<>', '');
        } elseif ($barcode === 'missing') {
            $q->where(function ($sub): void {
                $sub->whereNull('barcode')
                    ->orWhere('barcode', '=', '');
            });
        }

        $vendors = array_values(array_unique(array_filter(array_map('trim', $vendors), static fn (string $v): bool => $v !== '')));
        if ($vendors !== []) {
            $q->whereIn('vendor', $vendors);
        }

        $types = array_values(array_unique(array_filter(array_map('trim', $types), static fn (string $v): bool => $v !== '')));
        if ($types !== []) {
            $q->whereIn('type', $types);
        }

        $search = $search !== null ? trim($search) : null;
        if ($search !== null && $search !== '') {
            $q->where(function ($sub) use ($search): void {
                $sub->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $freshness = array_values(array_unique(array_filter(array_map('trim', $freshness), static fn (string $v): bool => $v !== '')));
        if ($freshness !== [] && count($freshness) === 1) {
            if ($freshness[0] === 'fresh') {
                $q->whereNotNull('price_researched_at')
                    ->where('price_researched_at', '>=', now()->subDays(max(1, (int) config('price_research.ttl_days', 14))));
            } elseif ($freshness[0] === 'expired') {
                $q->where(function ($sub): void {
                    $sub->whereNull('price_researched_at')
                        ->orWhere('price_researched_at', '<', now()->subDays(max(1, (int) config('price_research.ttl_days', 14))));
                });
            }
        }

        $quoteSites = array_values(array_unique(array_filter(array_map('trim', $quoteSites), static fn (string $v): bool => $v !== '')));
        $quoteStatuses = array_values(array_unique(array_filter(array_map('trim', $quoteStatuses), static fn (string $v): bool => $v !== '')));
        $quoteAvailabilities = array_values(array_unique(array_filter(array_map('trim', $quoteAvailabilities), static fn (string $v): bool => $v !== '')));

        if ($quoteSites !== [] || $quoteStatuses !== [] || $quoteAvailabilities !== []) {
            $q->whereHas('priceQuotes', function ($qq) use ($quoteSites, $quoteStatuses, $quoteAvailabilities): void {
                if ($quoteSites !== []) {
                    $qq->whereIn('site_key', $quoteSites);
                }
                if ($quoteStatuses !== []) {
                    $qq->whereIn('status', $quoteStatuses);
                }
                if ($quoteAvailabilities !== []) {
                    $qq->whereIn('availability', $quoteAvailabilities);
                }
            });
        }

        // Nulls last for researched_at; for other columns default ordering is fine.
        if ($sortColumn === 'price_researched_at') {
            $q->orderByRaw('price_researched_at is null asc');
        }

        if ($sortBy === 'multiplier') {
            $q->orderByRaw(
                'case when sps.selling_price is null or products.latest_landed_unit_cost is null or products.latest_landed_unit_cost = 0 then 1 else 0 end asc',
            );
            $q->orderByRaw('(sps.selling_price / products.latest_landed_unit_cost) '.$sortDir);

            return $q->paginate($perPage);
        }

        if ($sortBy === 'selling_price') {
            $q->orderByRaw('sps.selling_price is null asc');
        }

        return $q->orderBy($sortColumn, $sortDir)->paginate($perPage);
    }
}
