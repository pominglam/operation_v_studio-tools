<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\DAL\StorePreorders\StorePreorderRepository;
use App\Models\StorePreorder;
use App\Support\StorePreorders\StorePreorderStatus;

final class StorePreorderCollectionOrderBuilder
{
    public function __construct(
        private readonly StorePreorderRepository $offers,
    ) {}

    /**
     * Open kits first (soonest close date), then closed (most recently closed first).
     *
     * @param  list<array{gid: string, sku: string}>  $rows
     * @return list<string>
     */
    public function orderedGids(array $rows): array
    {
        $skus = [];
        foreach ($rows as $row) {
            if ($row['sku'] !== '') {
                $skus[] = $row['sku'];
            }
        }
        $offers = $this->offers->mapByPlamodSkus($skus);

        $open = [];
        $closed = [];
        $unknown = [];
        foreach ($rows as $row) {
            $gid = $row['gid'];
            if ($gid === '') {
                continue;
            }
            $offer = $row['sku'] !== '' ? $offers->get($row['sku']) : null;
            if (! $offer instanceof StorePreorder) {
                $unknown[] = $this->sortRow($gid, '9999-12-31', $row['sku']);

                continue;
            }
            $date = $offer->window_ends_on?->format('Y-m-d') ?? '9999-12-31';
            $entry = $this->sortRow($gid, $date, $row['sku']);
            if ($offer->status === StorePreorderStatus::OPEN) {
                $open[] = $entry;
            } else {
                $closed[] = $entry;
            }
        }

        usort($open, $this->byDateAsc(...));
        usort($closed, $this->byDateDesc(...));

        $gids = [];
        foreach ([...$open, ...$closed, ...$unknown] as $row) {
            $gids[] = $row['gid'];
        }

        return array_values(array_unique($gids));
    }

    /**
     * @return array{gid: string, date: string, sku: string}
     */
    private function sortRow(string $gid, string $date, string $sku): array
    {
        return ['gid' => $gid, 'date' => $date, 'sku' => $sku];
    }

    /**
     * @param  array{gid: string, date: string, sku: string}  $left
     * @param  array{gid: string, date: string, sku: string}  $right
     */
    private function byDateAsc(array $left, array $right): int
    {
        return [$left['date'], $left['sku']] <=> [$right['date'], $right['sku']];
    }

    /**
     * @param  array{gid: string, date: string, sku: string}  $left
     * @param  array{gid: string, date: string, sku: string}  $right
     */
    private function byDateDesc(array $left, array $right): int
    {
        return [$right['date'], $left['sku']] <=> [$left['date'], $right['sku']];
    }
}
