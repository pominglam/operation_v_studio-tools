<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\PlamodInstockItem;
use App\Models\PlamodPreorder;
use App\Models\Product;
use App\Support\Plamod\PlamodPreorderIndexSort;
use App\Support\Plamod\PlamodPreorderInterestFilter;
use App\Support\StorePreorders\StorePreorderPickListFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class PlamodPreorderQueryService
{
    public function __construct(
        private readonly PlamodPreorderSettingsService $settings,
        private readonly StorePreorderRepository $storePreorders,
        private readonly PlamodPreorderLiveWindow $liveWindow,
    ) {}

    /**
     * @param  array<int, string>  $categories
     * @return LengthAwarePaginator<int, PlamodPreorder>
     */
    public function paginate(
        int $perPage = 50,
        ?bool $newOnly = null,
        ?string $search = null,
        ?string $storeOffer = null,
        ?string $sort = null,
        bool $includeClosed = false,
        array $categories = [],
        ?string $sortDir = null,
        bool $futureReleasesOnly = false,
        ?string $interest = null,
    ): LengthAwarePaginator {
        $excluded = $this->settings->get()['excluded_categories'];
        $offerFilter = StorePreorderPickListFilter::normalize($storeOffer);
        $interestFilter = PlamodPreorderInterestFilter::normalize($interest);
        $categoryFilter = $this->normalizeNames($categories);

        $query = PlamodPreorder::query()->active();
        $this->applyPickListScope($query, $newOnly, $offerFilter, $includeClosed, $futureReleasesOnly, $interestFilter);
        $this->applySearch($query, $search);
        $this->applyCategoryVisibility($query, $excluded, $categoryFilter);
        $this->applySort(
            $query,
            PlamodPreorderIndexSort::normalize($sort),
            PlamodPreorderIndexSort::normalizeDir($sortDir),
        );

        $paginator = $query->paginate(max(1, min(200, $perPage)));
        $this->attachStorePreorderState($paginator);
        $this->attachLiveSignals($paginator);

        return $paginator;
    }

    /**
     * @return array<int, string>
     */
    public function listCategories(): array
    {
        return PlamodPreorder::query()
            ->active()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(static fn (mixed $v): string => trim((string) $v))
            ->filter(static fn (string $v): bool => $v !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{category: string, count: int}>
     */
    public function listCategoryFacets(
        ?bool $newOnly = null,
        ?string $storeOffer = null,
        bool $includeClosed = false,
        bool $futureReleasesOnly = false,
        ?string $interest = null,
    ): array {
        $offerFilter = StorePreorderPickListFilter::normalize($storeOffer);
        $interestFilter = PlamodPreorderInterestFilter::normalize($interest);
        $query = PlamodPreorder::query()->active();
        $this->applyPickListScope($query, $newOnly, $offerFilter, $includeClosed, $futureReleasesOnly, $interestFilter);

        $counts = $query
            ->whereNotNull('category')
            ->selectRaw('category, COUNT(*) as kit_count')
            ->groupBy('category')
            ->pluck('kit_count', 'category');

        $countMap = [];
        foreach ($counts as $name => $count) {
            $key = trim((string) $name);
            if ($key === '') {
                continue;
            }
            $countMap[$key] = (int) $count;
        }

        $names = $this->listCategories();
        $facets = [];
        foreach ($names as $name) {
            $facets[] = [
                'category' => $name,
                'count' => $countMap[$name] ?? 0,
            ];
        }

        return $facets;
    }

    /**
     * @param  array<int, string>  $catalogSkus
     */
    public function isNewSku(string $sku, array $catalogSkus): bool
    {
        return ! in_array(trim($sku), $catalogSkus, true);
    }

    /**
     * @return array<int, string>
     */
    public function catalogSkus(): array
    {
        return Product::query()
            ->notArchived()
            ->whereNotNull('sku')
            ->pluck('sku')
            ->map(static fn (mixed $sku): string => trim((string) $sku))
            ->filter(static fn (string $sku): bool => $sku !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  LengthAwarePaginator<int, PlamodPreorder>  $paginator
     */
    public function attachStorePreorderState(LengthAwarePaginator $paginator): void
    {
        $this->attachStorePreorderStateToCollection($paginator->getCollection());
    }

    /**
     * @param  \Illuminate\Support\Collection<array-key, PlamodPreorder>  $rows
     */
    public function attachStorePreorderStateToCollection($rows): void
    {
        $skus = $rows->map(static fn (PlamodPreorder $row): string => trim((string) $row->sku))->all();
        $map = $this->storePreorders->mapByPlamodSkus($skus);

        foreach ($rows as $row) {
            $offer = $map->get(trim((string) $row->sku));
            $row->setAttribute('_store_preorder_status', $offer?->status);
            $row->setAttribute('_store_preorder_id', $offer?->uuid);
        }
    }

    /**
     * @param  LengthAwarePaginator<int, PlamodPreorder>  $paginator
     */
    public function attachLiveSignals(LengthAwarePaginator $paginator): void
    {
        $skus = $paginator->getCollection()
            ->map(static fn (PlamodPreorder $row): string => trim((string) $row->sku))
            ->all();
        $inStock = $skus === []
            ? []
            : array_flip(
                PlamodInstockItem::query()
                    ->whereIn('sku', $skus)
                    ->pluck('sku')
                    ->map(static fn (mixed $sku): string => trim((string) $sku))
                    ->all(),
            );

        foreach ($paginator->getCollection() as $row) {
            $row->setAttribute('_plamod_in_stock', isset($inStock[trim((string) $row->sku)]));
            $row->setAttribute('_plamod_preorder_closed', ! $this->liveWindow->isWindowOpen($row->po_due_date));
            $row->setAttribute('_plamod_stock_listing', ! $this->liveWindow->hasPreorderPrice($row->price_preorder));
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function normalizeNames(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed === '') {
                continue;
            }
            $out[$trimmed] = $trimmed;
        }

        return array_values($out);
    }

    /** @param Builder<PlamodPreorder> $query */
    private function applyPickListScope(
        Builder $query,
        ?bool $newOnly,
        string $offerFilter,
        bool $includeClosed,
        bool $futureReleasesOnly = false,
        string $interest = PlamodPreorderInterestFilter::INTERESTED,
    ): void {
        $query->when($newOnly === true, function (Builder $q): void {
            $catalogSkus = Product::query()
                ->notArchived()
                ->whereNotNull('sku')
                ->pluck('sku');
            $q->whereNotIn('sku', $catalogSkus);
        });
        $this->applyStoreOfferFilter($query, $offerFilter);
        if (! $includeClosed && $offerFilter !== StorePreorderPickListFilter::OPENED) {
            $this->liveWindow->constrainToLivePickList($query);
        }
        if ($futureReleasesOnly) {
            $this->liveWindow->constrainToFutureReleases($query);
        }
        $this->applyInterestFilter($query, $interest);
    }

    /** @param Builder<PlamodPreorder> $query */
    private function applyInterestFilter(Builder $query, string $interest): void
    {
        if ($interest === PlamodPreorderInterestFilter::ALL) {
            return;
        }

        if ($interest === PlamodPreorderInterestFilter::NOT_INTERESTED) {
            $query->whereNotNull('not_interested_at');

            return;
        }

        $query->whereNull('not_interested_at');
    }

    /** @param Builder<PlamodPreorder> $query */
    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(function (Builder $sub) use ($term): void {
            $sub->where('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%")
                ->orWhere('product_name', 'like', "%{$term}%");
        });
    }

    /**
     * @param  Builder<PlamodPreorder>  $query
     * @param  array<int, string>  $excluded
     * @param  array<int, string>  $categoryFilter
     */
    private function applyCategoryVisibility(Builder $query, array $excluded, array $categoryFilter): void
    {
        if ($categoryFilter !== []) {
            $query->whereIn('category', $categoryFilter);

            return;
        }

        if ($excluded === []) {
            return;
        }

        $query->where(function (Builder $sub) use ($excluded): void {
            $sub->whereNull('category')->orWhereNotIn('category', $excluded);
        });
    }

    /** @param Builder<PlamodPreorder> $query */
    private function applyStoreOfferFilter(Builder $query, string $offerFilter): void
    {
        if ($offerFilter === StorePreorderPickListFilter::ALL) {
            return;
        }

        $openedSkus = $this->storePreorders->plamodSkus();
        if ($offerFilter === StorePreorderPickListFilter::OPENED) {
            $query->whereIn('sku', $openedSkus);

            return;
        }

        if ($openedSkus !== []) {
            $query->whereNotIn('sku', $openedSkus);
        }
    }

    /** @param Builder<PlamodPreorder> $query */
    private function applySort(Builder $query, string $sort, string $dir): void
    {
        match ($sort) {
            PlamodPreorderIndexSort::RELEASE => $this->orderNullableColumn($query, 'release_date', $dir),
            PlamodPreorderIndexSort::CATEGORY => $this->orderNullableColumn($query, 'category', $dir),
            PlamodPreorderIndexSort::STOCK, PlamodPreorderIndexSort::SELL => $this->orderCost($query, $dir),
            PlamodPreorderIndexSort::QTY => $this->orderNullableColumn($query, 'quantity_preorder', $dir),
            PlamodPreorderIndexSort::ETA => $this->orderNullableColumn($query, 'eta_date', $dir),
            PlamodPreorderIndexSort::ETA_MONTHS => $this->orderEtaMonths($query, $dir),
            PlamodPreorderIndexSort::NAME => $query->orderBy('product_name', $dir)->orderBy('sku', $dir),
            default => $this->orderNullableColumn($query, 'po_due_date', $dir),
        };
        if ($sort !== PlamodPreorderIndexSort::NAME) {
            $query->orderBy('product_name')->orderBy('sku');
        }
    }

    /** @param Builder<PlamodPreorder> $query */
    private function orderNullableColumn(Builder $query, string $column, string $dir): void
    {
        $query->orderByRaw($column.' is null')->orderBy($column, $dir);
    }

    /** @param Builder<PlamodPreorder> $query */
    private function orderCost(Builder $query, string $dir): void
    {
        $query->orderByRaw('COALESCE(price_preorder, price_stock) is null')
            ->orderByRaw('COALESCE(price_preorder, price_stock) '.$dir);
    }

    /** @param Builder<PlamodPreorder> $query */
    private function orderEtaMonths(Builder $query, string $dir): void
    {
        $query->orderByRaw('(release_date is null or eta_date is null)')
            ->orderByRaw(
                '((CAST(YEAR(eta_date) AS SIGNED) * 12 + CAST(MONTH(eta_date) AS SIGNED))'
                .' - (CAST(YEAR(release_date) AS SIGNED) * 12 + CAST(MONTH(release_date) AS SIGNED))) '.$dir,
            );
    }
}
