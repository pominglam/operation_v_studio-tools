<?php

declare(strict_types=1);

namespace App\DAL\PriceResearch;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface ProductLookupRepository
{
    public function findByUuidOrFail(string $uuid): Product;

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, Product>
     */
    public function findByUuids(array $uuids): Collection;

    /**
     * @return LazyCollection<int, Product>
     */
    public function cursorAll(): LazyCollection;
}
