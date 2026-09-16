<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductSavedViewRepository;
use App\DTOs\Products\ProductSavedViewWriteResult;
use App\Models\ProductSavedView;
use App\Services\Products\Exceptions\ProductSavedViewNameConflictException;
use App\Services\Products\Exceptions\ProductSavedViewNotFoundException;
use App\Support\Products\ProductSavedViewWriteData;
use Illuminate\Support\Collection;

final class ProductSavedViewService
{
    public function __construct(
        private readonly ProductSavedViewRepository $views,
    ) {}

    /**
     * @return Collection<int, ProductSavedView>
     */
    public function list(): Collection
    {
        return $this->views->list();
    }

    public function upsert(ProductSavedViewWriteData $data): ProductSavedViewWriteResult
    {
        $existing = $this->views->findByNormalizedName($data->name);
        if ($existing !== null) {
            return new ProductSavedViewWriteResult(
                $this->views->update($existing, $this->attributes($data)),
                false,
            );
        }

        return new ProductSavedViewWriteResult(
            $this->views->create($this->attributes($data)),
            true,
        );
    }

    public function update(string $uuid, ProductSavedViewWriteData $data): ProductSavedView
    {
        $view = $this->require($uuid);
        $sameName = $this->views->findByNormalizedName($data->name);
        if ($sameName !== null && $sameName->uuid !== $view->uuid) {
            throw new ProductSavedViewNameConflictException('A saved view already uses that name.');
        }

        return $this->views->update($view, $this->attributes($data));
    }

    public function delete(string $uuid): void
    {
        $this->views->delete($this->require($uuid));
    }

    public function require(string $uuid): ProductSavedView
    {
        $view = $this->views->findByUuid($uuid);
        if ($view === null) {
            throw new ProductSavedViewNotFoundException('Saved view not found.');
        }

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProductSavedViewWriteData $data): array
    {
        return [
            'name' => $data->name,
            'snapshot' => $data->snapshot,
            'visible_columns' => $data->visibleColumns,
        ];
    }
}
