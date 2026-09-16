<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\Models\ProductSavedView;
use Illuminate\Support\Collection;

final class EloquentProductSavedViewRepository implements ProductSavedViewRepository
{
    public function list(): Collection
    {
        return ProductSavedView::query()->orderBy('name')->get();
    }

    public function findByUuid(string $uuid): ?ProductSavedView
    {
        return ProductSavedView::query()->where('uuid', $uuid)->first();
    }

    public function findByNormalizedName(string $name): ?ProductSavedView
    {
        return ProductSavedView::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    public function create(array $attributes): ProductSavedView
    {
        return ProductSavedView::query()->create($attributes);
    }

    public function update(ProductSavedView $view, array $attributes): ProductSavedView
    {
        $view->fill($attributes);
        $view->save();

        return $view->refresh();
    }

    public function delete(ProductSavedView $view): void
    {
        $view->delete();
    }
}
