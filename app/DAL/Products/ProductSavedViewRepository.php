<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductSavedView;
use Illuminate\Support\Collection;

interface ProductSavedViewRepository
{
    /**
     * @return Collection<int, ProductSavedView>
     */
    public function list(): Collection;

    public function findByUuid(string $uuid): ?ProductSavedView;

    public function findByNormalizedName(string $name): ?ProductSavedView;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): ProductSavedView;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProductSavedView $view, array $attributes): ProductSavedView;

    public function delete(ProductSavedView $view): void;
}
