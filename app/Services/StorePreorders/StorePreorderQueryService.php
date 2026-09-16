<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\StorePreorder;
use App\Support\StorePreorders\StorePreorderIndexSort;
use App\Support\StorePreorders\StorePreorderStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class StorePreorderQueryService
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
        private readonly StorePreorderEtaLookup $etas,
        private readonly StorePreorderOrderStatsService $orderStats,
    ) {}

    public function paginate(
        int $perPage,
        ?string $sortBy,
        ?string $sortDir,
        ?string $status,
        ?string $search,
        ?string $closesOn = null,
        bool $hasUnits = false,
    ): LengthAwarePaginator {
        $paginator = $this->offers->paginate(
            $perPage,
            StorePreorderIndexSort::normalize($sortBy),
            StorePreorderIndexSort::normalizeDir($sortDir),
            StorePreorderStatus::normalize($status),
            $search,
            $closesOn,
            $hasUnits,
        );
        $this->attachEtas($paginator);
        $this->orderStats->attachToOffers($paginator->items());

        return $paginator;
    }

    /**
     * @return list<string>
     */
    public function closingDates(?string $status): array
    {
        return $this->offers->closingDates($status);
    }

    private function attachEtas(LengthAwarePaginator $paginator): void
    {
        $skus = [];
        foreach ($paginator->items() as $offer) {
            if (! $offer instanceof StorePreorder) {
                continue;
            }
            $sku = trim((string) $offer->plamod_sku);
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        $etas = $this->etas->mapBySkus($skus);
        foreach ($paginator->items() as $offer) {
            if (! $offer instanceof StorePreorder) {
                continue;
            }
            if ($offer->eta_date !== null) {
                continue;
            }
            $lookup = $etas[trim((string) $offer->plamod_sku)] ?? null;
            if ($lookup !== null) {
                $offer->setAttribute('eta_date', $lookup);
            }
        }
    }
}
