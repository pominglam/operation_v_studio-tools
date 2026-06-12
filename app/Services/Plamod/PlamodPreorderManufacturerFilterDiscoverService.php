<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodPreorderManufacturerFilterType;
use App\Models\PlamodPreorderManufacturerFilter;
use App\Services\Products\Http\PlamodScraper;
use Illuminate\Support\Carbon;

final class PlamodPreorderManufacturerFilterDiscoverService
{
    public function __construct(
        private readonly PlamodScraper $scraper,
        private readonly PlamodPreorderManufacturerFilterBootstrap $bootstrap,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     error_message?: string,
     *     series_discovered?: int,
     *     category_lines_discovered?: int,
     *     undecided_count?: int,
     *     include_count?: int,
     *     exclude_count?: int
     * }
     */
    public function discover(int $manufacturerId = 1): array
    {
        $result = $this->scraper->listManufacturerPreorderFilters($manufacturerId);
        if (($result['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'error_message' => (string) ($result['error_message'] ?? 'Failed to list Bandai manufacturer filters'),
            ];
        }

        $now = Carbon::now();
        $seriesDiscovered = 0;
        $categoryDiscovered = 0;

        foreach (($result['series'] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->upsertFilter(
                $manufacturerId,
                PlamodPreorderManufacturerFilterType::Series,
                $name,
                $this->nullableInt($item['preorder_count'] ?? null),
                $this->nullableInt($item['other_count'] ?? null),
                $now,
            );
            $seriesDiscovered++;
        }

        foreach (($result['category_lines'] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $this->upsertFilter(
                $manufacturerId,
                PlamodPreorderManufacturerFilterType::CategoryLine,
                $name,
                $this->nullableInt($item['preorder_count'] ?? null),
                $this->nullableInt($item['other_count'] ?? null),
                $now,
            );
            $categoryDiscovered++;
        }

        return [
            'ok' => true,
            'series_discovered' => $seriesDiscovered,
            'category_lines_discovered' => $categoryDiscovered,
            ...$this->decisionCounts($manufacturerId),
        ];
    }

    private function upsertFilter(
        int $manufacturerId,
        PlamodPreorderManufacturerFilterType $filterType,
        string $name,
        ?int $preorderCount,
        ?int $otherCount,
        Carbon $now,
    ): void {
        $existing = PlamodPreorderManufacturerFilter::query()
            ->where('manufacturer_id', $manufacturerId)
            ->where('filter_type', $filterType)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'plamod_preorder_count' => $preorderCount,
                'plamod_other_count' => $otherCount,
                'last_seen_at' => $now,
            ]);

            return;
        }

        PlamodPreorderManufacturerFilter::query()->create([
            'manufacturer_id' => $manufacturerId,
            'filter_type' => $filterType,
            'name' => $name,
            'plamod_preorder_count' => $preorderCount,
            'plamod_other_count' => $otherCount,
            'decision' => $this->bootstrap->defaultDecisionFor($filterType->value, $name),
            'last_seen_at' => $now,
        ]);
    }

    /**
     * @return array{undecided_count: int, include_count: int, exclude_count: int}
     */
    public function decisionCounts(int $manufacturerId = 1): array
    {
        $rows = PlamodPreorderManufacturerFilter::query()
            ->where('manufacturer_id', $manufacturerId)
            ->selectRaw('decision, COUNT(*) as c')
            ->groupBy('decision')
            ->pluck('c', 'decision');

        return [
            'undecided_count' => (int) ($rows['undecided'] ?? 0),
            'include_count' => (int) ($rows['include'] ?? 0),
            'exclude_count' => (int) ($rows['exclude'] ?? 0),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }
}
