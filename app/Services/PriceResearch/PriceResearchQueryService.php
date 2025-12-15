<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PriceResearchQueryService
{
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
        array $freshness = [],
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
            'cost' => 'price',
        ];
        $sortColumn = $sortBy !== null && array_key_exists($sortBy, $sortMap) ? $sortMap[$sortBy] : 'price_researched_at';

        $q = Product::query()
            ->with(['priceQuotes' => function ($q): void {
                $q->orderBy('site_key');
            }])
            ->with('sellingPrice')
            ->select('products.*');

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

        return $q->orderBy($sortColumn, $sortDir)->paginate($perPage);
    }
}
