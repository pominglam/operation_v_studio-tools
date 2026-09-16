<?php

declare(strict_types=1);

namespace App\Services\StorePreorders;

use App\Models\PlamodPreorder;
use App\Models\PlamodPreorderOffer;
use Carbon\CarbonInterface;

final class StorePreorderEtaLookup
{
    /**
     * @param  list<string>  $skus
     * @return array<string, string>
     */
    public function mapBySkus(array $skus): array
    {
        $normalized = [];
        foreach ($skus as $sku) {
            $sku = trim($sku);
            if ($sku !== '') {
                $normalized[$sku] = $sku;
            }
        }
        if ($normalized === []) {
            return [];
        }

        $map = [];
        $this->fillFromHub($map, array_values($normalized));
        $missing = array_values(array_diff(array_values($normalized), array_keys($map)));
        $this->fillFromOffers($map, $missing);

        return $map;
    }

    public function forSku(string $sku): ?string
    {
        $map = $this->mapBySkus([$sku]);

        return $map[trim($sku)] ?? null;
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $skus
     */
    private function fillFromHub(array &$map, array $skus): void
    {
        if ($skus === []) {
            return;
        }

        foreach (PlamodPreorder::query()->whereIn('sku', $skus)->get(['sku', 'eta_date']) as $row) {
            $day = $this->dateString($row->eta_date);
            $sku = trim((string) $row->sku);
            if ($day !== null && $sku !== '') {
                $map[$sku] = $day;
            }
        }
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $skus
     */
    private function fillFromOffers(array &$map, array $skus): void
    {
        if ($skus === []) {
            return;
        }

        $rows = PlamodPreorderOffer::query()
            ->whereIn('sku', $skus)
            ->whereNotNull('eta_date')
            ->get(['sku', 'eta_date']);
        foreach ($rows->groupBy('sku') as $sku => $group) {
            $day = $this->dateString($group->max('eta_date'));
            $key = trim((string) $sku);
            if ($day !== null && $key !== '') {
                $map[$key] = $day;
            }
        }
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
    }
}
