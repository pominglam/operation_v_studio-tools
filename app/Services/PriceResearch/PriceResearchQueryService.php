<?php

declare(strict_types=1);

namespace App\Services\PriceResearch;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PriceResearchQueryService
{
    public function paginateProductsWithQuotes(int $perPage): LengthAwarePaginator
    {
        return Product::query()
            ->with(['priceQuotes' => function ($q): void {
                $q->orderBy('site_key');
            }])
            ->orderByDesc('price_researched_at')
            ->paginate($perPage);
    }
}


