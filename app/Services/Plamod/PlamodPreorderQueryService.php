<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Models\PlamodPreorder;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class PlamodPreorderQueryService
{
    public function __construct(
        private readonly PlamodPreorderSettingsService $settings,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PlamodPreorder>
     */
    public function paginate(
        int $perPage = 50,
        ?bool $newOnly = null,
        ?string $search = null,
    ): LengthAwarePaginator {
        $excluded = $this->settings->get()['excluded_categories'];

        $query = PlamodPreorder::query()
            ->active()
            ->when($excluded !== [], function (Builder $q) use ($excluded): void {
                $q->where(function (Builder $sub) use ($excluded): void {
                    $sub->whereNull('category')->orWhereNotIn('category', $excluded);
                });
            })
            ->when($newOnly === true, function (Builder $q): void {
                $catalogSkus = Product::query()
                    ->notArchived()
                    ->whereNotNull('sku')
                    ->pluck('sku');
                $q->whereNotIn('sku', $catalogSkus);
            })
            ->when($search !== null && trim($search) !== '', function (Builder $q) use ($search): void {
                $term = trim($search);
                $q->where(function (Builder $sub) use ($term): void {
                    $sub->where('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%")
                        ->orWhere('product_name', 'like', "%{$term}%");
                });
            })
            ->orderBy('product_name');

        return $query->paginate(max(1, min(200, $perPage)));
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
}
