<?php

declare(strict_types=1);

namespace App\DAL\StorePreorders;

use App\Models\StorePreorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface StorePreorderRepository
{
    public function paginate(
        int $perPage,
        string $sortBy,
        string $sortDir,
        ?string $status,
        ?string $search,
        ?string $closesOn = null,
        bool $hasUnits = false,
    ): LengthAwarePaginator;

    /**
     * @return list<string>
     */
    public function closingDates(?string $status): array;

    public function findByUuidOrFail(string $uuid): StorePreorder;

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, StorePreorder>
     */
    public function findByUuids(array $uuids): Collection;

    /**
     * @return Collection<int, StorePreorder>
     */
    public function listOpen(): Collection;

    /**
     * Open offers whose closing day is strictly before `$ymd` (Y-m-d). Blank closing dates are omitted.
     *
     * @return Collection<int, StorePreorder>
     */
    public function listOpenEndedBefore(string $ymd): Collection;

    public function findByPlamodSku(string $sku): ?StorePreorder;

    public function findOpenByProductUuid(string $productUuid): ?StorePreorder;

    public function existsForProductId(int $productId): bool;

    public function existsForProductUuid(string $productUuid): bool;

    /**
     * @return Collection<int, StorePreorder>
     */
    public function listAll(): Collection;

    /**
     * @param  array<int, string>  $skus
     * @return Collection<string, StorePreorder>
     */
    public function mapByPlamodSkus(array $skus): Collection;

    /**
     * @return array<int, string>
     */
    public function plamodSkus(): array;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StorePreorder;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StorePreorder $offer, array $attributes): StorePreorder;

    public function delete(StorePreorder $offer): void;
}
