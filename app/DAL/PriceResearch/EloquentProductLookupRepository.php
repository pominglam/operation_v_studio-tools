<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class EloquentProductLookupRepository implements ProductLookupRepository
{
    public function findByUuids(array $uuids): Collection
    {
        if ($uuids === []) {
            return collect();
        }

        return Product::query()
            ->whereIn('uuid', $uuids)
            ->get();
    }

    public function cursorAll(): LazyCollection
    {
        return Product::query()->orderBy('id')->cursor();
    }
}


