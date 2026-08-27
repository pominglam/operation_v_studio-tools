<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductTaxonomyProposalDTO;
use App\Models\Product;
use App\Models\ProductTaxonomyResearchRun;
use App\Models\ProductTaxonomyVerification;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class EloquentProductTaxonomyRepository implements ProductTaxonomyRepository
{
    public function createRun(string $researchVersion): ProductTaxonomyResearchRun
    {
        return ProductTaxonomyResearchRun::query()->create([
            'status' => 'running',
            'research_version' => $researchVersion,
            'counts_json' => [
                'processed' => 0,
                'proposed' => 0,
                'failed' => 0,
            ],
            'started_at' => now(),
        ]);
    }

    public function createQueuedRun(string $researchVersion): ProductTaxonomyResearchRun
    {
        return ProductTaxonomyResearchRun::query()->create([
            'status' => 'queued',
            'research_version' => $researchVersion,
            'counts_json' => [
                'processed' => 0,
                'proposed' => 0,
                'failed' => 0,
            ],
        ]);
    }

    public function findRunByUuid(string $uuid): ProductTaxonomyResearchRun
    {
        return ProductTaxonomyResearchRun::query()
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function findRunByUuidForUpdate(string $uuid): ProductTaxonomyResearchRun
    {
        return ProductTaxonomyResearchRun::query()
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function saveRun(ProductTaxonomyResearchRun $run): ProductTaxonomyResearchRun
    {
        $run->save();

        return $run->refresh();
    }

    public function createVerification(
        Product $product,
        ProductTaxonomyResearchRun $run,
        ProductTaxonomyProposalDTO $proposal,
        string $researchMethod,
    ): ProductTaxonomyVerification {
        return ProductTaxonomyVerification::query()->create([
            'product_id' => $product->id,
            'research_run_id' => $run->id,
            'status' => 'proposed',
            'research_version' => $run->research_version,
            'proposed_values_json' => $proposal->values,
            'previous_values_json' => ProductTaxonomyFields::fromProduct($product),
            'evidence_json' => $proposal->evidence,
            'overall_confidence' => $proposal->overallConfidence,
            'research_method' => $researchMethod,
            'operator_notes' => $proposal->notes !== [] ? implode(' ', $proposal->notes) : null,
            'researched_at' => now(),
        ]);
    }

    public function findVerificationByUuidForUpdate(string $uuid): ProductTaxonomyVerification
    {
        $verification = ProductTaxonomyVerification::query()
            ->with(['product'])
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->first();

        if ($verification === null) {
            throw (new ModelNotFoundException)->setModel(ProductTaxonomyVerification::class, [$uuid]);
        }

        return $verification;
    }

    public function saveVerification(ProductTaxonomyVerification $verification): ProductTaxonomyVerification
    {
        $verification->save();

        return $verification->refresh();
    }

    public function paginateVerifications(
        int $perPage,
        array $filters,
    ): LengthAwarePaginator {
        $query = ProductTaxonomyVerification::query()
            ->with(['product'])
            ->latest('id');
        $latestRunId = $this->latestCompletedRunId();
        if ($latestRunId !== null) {
            $query->where('research_run_id', $latestRunId);
        }

        $this->applyReviewFilters($query, $filters);
        $this->excludeAgentTestSkus($query);

        return $query->paginate($perPage);
    }

    public function listVerifications(array $filters): Collection
    {
        $query = ProductTaxonomyVerification::query()
            ->with(['product'])
            ->latest('id');
        $latestRunId = $this->latestCompletedRunId();
        if ($latestRunId !== null) {
            $query->where('research_run_id', $latestRunId);
        }

        $this->applyReviewFilters($query, $filters);
        $this->excludeAgentTestSkus($query);

        return $query->get();
    }

    public function findVerificationsByUuids(array $uuids): Collection
    {
        if ($uuids === []) {
            return new Collection;
        }

        return ProductTaxonomyVerification::query()
            ->with(['product'])
            ->whereIn('uuid', $uuids)
            ->get();
    }

    public function verificationSummary(): array
    {
        $query = ProductTaxonomyVerification::query();
        $latestRunId = $this->latestCompletedRunId();
        if ($latestRunId !== null) {
            $query->where('research_run_id', $latestRunId);
        }
        $this->excludeAgentTestSkus($query);

        return [
            'total' => (clone $query)->count(),
            'proposed' => (clone $query)->where('status', 'proposed')->count(),
            'verified' => (clone $query)->where('status', 'verified')->count(),
            'overridden' => (clone $query)->where('status', 'overridden')->count(),
            'low_confidence' => (clone $query)
                ->where('status', 'proposed')
                ->where('overall_confidence', '<=', 75)
                ->count(),
        ];
    }

    public function verificationFilterOptions(): array
    {
        $options = [];
        $filterMap = [
            'departments' => 'department',
            'manufacturers' => 'manufacturer',
            'franchises' => 'franchise',
            'product_lines' => 'product_line',
            'sublines' => 'subline',
            'grades' => 'grade',
            'series' => 'series',
            'scales' => 'scale',
            'workshop_shelves' => 'workshop_shelf',
        ];
        foreach ($filterMap as $key => $field) {
            $options[$key] = match ($key) {
                'workshop_shelves' => \App\Support\Products\WorkshopShelfCatalog::shelfLabels(),
                'accessory_kinds' => \App\Support\Products\ModelKitAccessoryKind::values(),
                default => $this->distinctVerificationValues($field),
            };
        }

        return $options;
    }

    private function latestCompletedRunId(): ?int
    {
        $id = ProductTaxonomyResearchRun::query()
            ->whereIn('status', ['completed', 'completed_with_errors'])
            ->latest('id')
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return array<int, string>
     */
    private function distinctVerificationValues(string $field): array
    {
        $query = ProductTaxonomyVerification::query();
        $latestRunId = $this->latestCompletedRunId();
        if ($latestRunId !== null) {
            $query->where('research_run_id', $latestRunId);
        }
        $path = '$."'.$field.'"';
        $expression = $query->getConnection()->getDriverName() === 'sqlite'
            ? 'JSON_EXTRACT(proposed_values_json, ?)'
            : 'JSON_UNQUOTE(JSON_EXTRACT(proposed_values_json, ?))';

        return $query->selectRaw($expression.' AS value', [$path])
            ->whereRaw($expression.' IS NOT NULL', [$path])
            ->distinct()
            ->orderBy('value')
            ->pluck('value')
            ->filter(static fn (mixed $value): bool => is_string($value)
                && trim($value) !== ''
                && trim($value) !== 'null')
            ->map(static fn (string $value): string => $value)
            ->values()
            ->all();
    }

    /**
     * @param  Builder<ProductTaxonomyVerification>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyReviewFilters(Builder $query, array $filters): void
    {
        $this->applyScalarReviewFilters($query, $filters);
        $this->applyCanonicalReviewFilters($query, $filters);
        $this->applyProductReviewFilters($query, $filters);

        if (($filters['differences_only'] ?? false) === true) {
            $isSqlite = $query->getConnection()->getDriverName() === 'sqlite';
            $query->where(function (Builder $differenceQuery) use ($isSqlite): void {
                foreach (ProductTaxonomyFields::ALL as $field) {
                    $path = '$."'.$field.'"';
                    $differenceQuery->orWhereRaw(
                        $isSqlite
                            ? 'JSON_EXTRACT(proposed_values_json, ?) IS NOT JSON_EXTRACT(previous_values_json, ?)'
                            : 'NOT (JSON_EXTRACT(proposed_values_json, ?) <=> JSON_EXTRACT(previous_values_json, ?))',
                        [$path, $path],
                    );
                }
            });
        }
    }

    /**
     * @param  Builder<ProductTaxonomyVerification>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyScalarReviewFilters(Builder $query, array $filters): void
    {
        if (is_string($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (is_numeric($filters['maximum_confidence'] ?? null)) {
            $query->where('overall_confidence', '<=', (int) $filters['maximum_confidence']);
        }
        if (is_numeric($filters['minimum_confidence'] ?? null)) {
            $query->where('overall_confidence', '>=', (int) $filters['minimum_confidence']);
        }
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        if ($search !== '') {
            $query->whereHas('product', static function (Builder $productQuery) use ($search): void {
                $term = '%'.$search.'%';
                $productQuery->where('sku', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }
    }

    /**
     * @param  Builder<ProductTaxonomyVerification>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyCanonicalReviewFilters(Builder $query, array $filters): void
    {
        $fieldMap = [
            'departments' => 'department',
            'manufacturers' => 'manufacturer',
            'franchises' => 'franchise',
            'product_lines' => 'product_line',
            'sublines' => 'subline',
            'grades' => 'grade',
            'series_values' => 'series',
            'scales' => 'scale',
            'workshop_shelves' => 'workshop_shelf',
            'accessory_kinds' => 'accessory_kind',
        ];
        foreach ($fieldMap as $filter => $field) {
            $values = $filters[$filter] ?? [];
            if (is_array($values) && $values !== []) {
                $query->whereIn('proposed_values_json->'.$field, $values);
            }
        }
        $missingFields = $filters['missing_fields'] ?? [];
        if (is_array($missingFields)) {
            foreach ($missingFields as $field) {
                if (is_string($field) && in_array($field, ProductTaxonomyFields::ALL, true)) {
                    $query->whereNull('proposed_values_json->'.$field);
                }
            }
        }
    }

    /**
     * @param  Builder<ProductTaxonomyVerification>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyProductReviewFilters(Builder $query, array $filters): void
    {
        $archived = $filters['archived'] ?? 'all';
        if ($archived === 'active') {
            $query->whereHas('product', static function (Builder $productQuery): void {
                $productQuery->whereNull('archived_at');
            });
        }
        if ($archived === 'archived') {
            $query->whereHas('product', static function (Builder $productQuery): void {
                $productQuery->whereNotNull('archived_at');
            });
        }
    }

    /**
     * @param  Builder<ProductTaxonomyVerification>  $query
     */
    private function excludeAgentTestSkus(Builder $query): void
    {
        $query->whereHas('product', static function (Builder $productQuery): void {
            AgentTestSkuGuard::excludeFromProductQuery($productQuery);
        });
    }
}
