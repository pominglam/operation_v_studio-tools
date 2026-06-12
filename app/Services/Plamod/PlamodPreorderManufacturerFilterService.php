<?php

declare(strict_types=1);

namespace App\Services\Plamod;

use App\Enums\PlamodPreorderManufacturerFilterDecision;
use App\Models\PlamodPreorderManufacturerFilter;
use Illuminate\Support\Collection;

final class PlamodPreorderManufacturerFilterService
{
    /**
     * @return array{
     *     undecided: array<int, array<string, mixed>>,
     *     include: array<int, array<string, mixed>>,
     *     exclude: array<int, array<string, mixed>>,
     *     counts: array{undecided: int, include: int, exclude: int}
     * }
     */
    public function listGrouped(int $manufacturerId = 1): array
    {
        $rows = PlamodPreorderManufacturerFilter::query()
            ->where('manufacturer_id', $manufacturerId)
            ->orderBy('filter_type')
            ->orderBy('name')
            ->get();

        return [
            'undecided' => $this->serializeGroup($rows, PlamodPreorderManufacturerFilterDecision::Undecided),
            'include' => $this->serializeGroup($rows, PlamodPreorderManufacturerFilterDecision::Include),
            'exclude' => $this->serializeGroup($rows, PlamodPreorderManufacturerFilterDecision::Exclude),
            'counts' => [
                'undecided' => $rows->where('decision', PlamodPreorderManufacturerFilterDecision::Undecided)->count(),
                'include' => $rows->where('decision', PlamodPreorderManufacturerFilterDecision::Include)->count(),
                'exclude' => $rows->where('decision', PlamodPreorderManufacturerFilterDecision::Exclude)->count(),
            ],
        ];
    }

    /**
     * @param  array<int, array{id: int, decision: string}>  $updates
     * @return array{updated: int}
     */
    public function updateDecisions(array $updates): array
    {
        $updated = 0;
        foreach ($updates as $update) {
            $id = (int) ($update['id'] ?? 0);
            $decision = PlamodPreorderManufacturerFilterDecision::tryFrom((string) ($update['decision'] ?? ''));
            if ($id <= 0 || $decision === null) {
                continue;
            }

            $count = PlamodPreorderManufacturerFilter::query()
                ->whereKey($id)
                ->update(['decision' => $decision]);
            $updated += $count;
        }

        return ['updated' => $updated];
    }

    /**
     * @return Collection<int, PlamodPreorderManufacturerFilter>
     */
    public function includedFilters(int $manufacturerId = 1): Collection
    {
        return PlamodPreorderManufacturerFilter::query()
            ->where('manufacturer_id', $manufacturerId)
            ->where('decision', PlamodPreorderManufacturerFilterDecision::Include)
            ->orderBy('filter_type')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, PlamodPreorderManufacturerFilter>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function serializeGroup(Collection $rows, PlamodPreorderManufacturerFilterDecision $decision): array
    {
        return $rows
            ->where('decision', $decision)
            ->values()
            ->map(static fn (PlamodPreorderManufacturerFilter $row): array => [
                'id' => $row->id,
                'filter_type' => $row->filter_type->value,
                'name' => $row->name,
                'plamod_preorder_count' => $row->plamod_preorder_count,
                'plamod_other_count' => $row->plamod_other_count,
                'decision' => $row->decision->value,
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            ])
            ->all();
    }
}
