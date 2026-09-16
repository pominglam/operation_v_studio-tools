<?php

declare(strict_types=1);

namespace App\DAL\StorePreorders;

use App\Models\Product;
use App\Models\StorePreorder;
use App\Support\StorePreorders\StorePreorderIndexSort;
use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

final class EloquentStorePreorderRepository implements StorePreorderRepository
{
    public function paginate(
        int $perPage,
        string $sortBy,
        string $sortDir,
        ?string $status,
        ?string $search,
        ?string $closesOn = null,
        bool $hasUnits = false,
    ): LengthAwarePaginator {
        $sortBy = StorePreorderIndexSort::normalize($sortBy);
        $sortDir = StorePreorderIndexSort::normalizeDir($sortDir);
        $status = StorePreorderStatus::normalize($status);

        $query = StorePreorder::query()->with(['product.shopifyImageAssets']);

        if ($status !== null) {
            $query->where('status', '=', $status);
        }
        $this->applyClosesOn($query, $closesOn);
        $this->applySearch($query, $search);
        $this->applyHasUnits($query, $hasUnits);
        $this->applySort($query, $sortBy, $sortDir);

        return $query->paginate(max(1, min(200, $perPage)));
    }

    /**
     * @return list<string>
     */
    public function closingDates(?string $status): array
    {
        $query = StorePreorder::query()->whereNotNull('window_ends_on');
        $normalized = StorePreorderStatus::normalize($status);
        if ($normalized !== null) {
            $query->where('status', '=', $normalized);
        }

        return $query
            ->orderBy('window_ends_on')
            ->distinct()
            ->pluck('window_ends_on')
            ->map(static fn (mixed $value): string => substr((string) $value, 0, 10))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    public function findByUuidOrFail(string $uuid): StorePreorder
    {
        /** @var StorePreorder $offer */
        $offer = StorePreorder::query()->with(['product'])->where('uuid', '=', $uuid)->firstOrFail();

        return $offer;
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, StorePreorder>
     */
    public function findByUuids(array $uuids): Collection
    {
        $uuids = array_values(array_unique(array_filter(
            array_map(static fn (string $uuid): string => trim($uuid), $uuids),
            static fn (string $uuid): bool => $uuid !== '',
        )));
        if ($uuids === []) {
            return new Collection;
        }

        return StorePreorder::query()->with(['product'])->whereIn('uuid', $uuids)->get();
    }

    /**
     * @return Collection<int, StorePreorder>
     */
    public function listOpen(): Collection
    {
        return StorePreorder::query()
            ->with(['product'])
            ->where('status', StorePreorderStatus::OPEN)
            ->get();
    }

    public function listOpenEndedBefore(string $ymd): Collection
    {
        $ymd = substr(trim($ymd), 0, 10);
        if ($ymd === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return new Collection;
        }

        return StorePreorder::query()
            ->with(['product'])
            ->where('status', StorePreorderStatus::OPEN)
            ->whereNotNull('window_ends_on')
            ->whereDate('window_ends_on', '<', $ymd)
            ->orderBy('window_ends_on')
            ->get();
    }

    public function findByPlamodSku(string $sku): ?StorePreorder
    {
        /** @var StorePreorder|null $offer */
        $offer = StorePreorder::query()->where('plamod_sku', '=', trim($sku))->first();

        return $offer;
    }

    public function findOpenByProductUuid(string $productUuid): ?StorePreorder
    {
        $productUuid = trim($productUuid);
        if ($productUuid === '') {
            return null;
        }

        /** @var StorePreorder|null $offer */
        $offer = StorePreorder::query()
            ->with(['product'])
            ->where('status', StorePreorderStatus::OPEN)
            ->whereHas('product', static function (Builder $product) use ($productUuid): void {
                $product->where('uuid', '=', $productUuid);
            })
            ->first();

        return $offer;
    }

    public function existsForProductId(int $productId): bool
    {
        return $productId > 0 && StorePreorder::query()->where('product_id', '=', $productId)->exists();
    }

    public function existsForProductUuid(string $productUuid): bool
    {
        $productUuid = trim($productUuid);
        if ($productUuid === '') {
            return false;
        }

        return StorePreorder::query()
            ->whereHas('product', static function (Builder $product) use ($productUuid): void {
                $product->where('uuid', '=', $productUuid);
            })
            ->exists();
    }

    /**
     * @return Collection<int, StorePreorder>
     */
    public function listAll(): Collection
    {
        return StorePreorder::query()->with(['product'])->orderBy('id')->get();
    }

    /**
     * @param  array<int, string>  $skus
     * @return Collection<string, StorePreorder>
     */
    public function mapByPlamodSkus(array $skus): Collection
    {
        $skus = array_values(array_unique(array_filter(
            array_map(static fn (string $sku): string => trim($sku), $skus),
            static fn (string $sku): bool => $sku !== '',
        )));
        if ($skus === []) {
            return collect();
        }

        return StorePreorder::query()
            ->whereIn('plamod_sku', $skus)
            ->get()
            ->keyBy(static fn (StorePreorder $row): string => (string) $row->plamod_sku);
    }

    /**
     * @return array<int, string>
     */
    public function plamodSkus(): array
    {
        return StorePreorder::query()
            ->orderBy('plamod_sku')
            ->pluck('plamod_sku')
            ->map(static fn (mixed $sku): string => trim((string) $sku))
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->values()
            ->all();
    }

    public function create(array $attributes): StorePreorder
    {
        /** @var StorePreorder $offer */
        $offer = StorePreorder::query()->create($attributes);

        return $offer->load(['product.shopifyImageAssets']);
    }

    public function update(StorePreorder $offer, array $attributes): StorePreorder
    {
        $offer->fill($attributes);
        $offer->save();

        return $offer->load(['product.shopifyImageAssets']);
    }

    public function delete(StorePreorder $offer): void
    {
        $offer->delete();
    }

    /** @param Builder<StorePreorder> $query */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(function (Builder $sub) use ($term): void {
            $sub->where('plamod_sku', 'like', "%{$term}%")
                ->orWhereHas('product', function (Builder $product) use ($term): void {
                    $product->where('sku', 'like', "%{$term}%")
                        ->orWhere('description', 'like', "%{$term}%");
                });
        });
    }

    /** @param Builder<StorePreorder> $query */
    private function applyClosesOn(Builder $query, ?string $closesOn): void
    {
        $date = is_string($closesOn) ? trim($closesOn) : '';
        if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $query->whereDate('window_ends_on', '=', $date);
    }

    /** @param Builder<StorePreorder> $query */
    private function applyHasUnits(Builder $query, bool $hasUnits): void
    {
        if (! $hasUnits) {
            return;
        }

        $query->whereExists(function (QueryBuilder $sub): void {
            $sub->selectRaw('1')
                ->from('shopify_order_line_items')
                ->join('shopify_orders', 'shopify_orders.gid', '=', 'shopify_order_line_items.order_gid')
                ->whereNull('shopify_orders.cancelled_at')
                ->where(function (QueryBuilder $financial): void {
                    $financial->whereNull('shopify_orders.display_financial_status')
                        ->orWhere('shopify_orders.display_financial_status', '!=', 'VOIDED');
                })
                ->where('shopify_order_line_items.quantity', '>', 0)
                ->where(function (QueryBuilder $match): void {
                    $match->whereColumn('shopify_order_line_items.product_id', 'store_preorders.product_id')
                        ->orWhereColumn('shopify_order_line_items.sku', 'store_preorders.plamod_sku');
                });
        });
    }

    /** @param Builder<StorePreorder> $query */
    private function applySort(Builder $query, string $sortBy, string $sortDir): void
    {
        if ($sortBy === 'name') {
            $query->orderBy(
                Product::query()->select('description')->whereColumn('products.id', 'store_preorders.product_id'),
                $sortDir,
            );

            return;
        }

        $column = match ($sortBy) {
            'opened' => 'opened_at',
            default => 'window_ends_on',
        };

        if ($column === 'window_ends_on') {
            $query->orderByRaw('window_ends_on is null')
                ->orderBy('window_ends_on', $sortDir);

            return;
        }

        $query->orderBy($column, $sortDir);
    }
}
