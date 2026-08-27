<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductTaxonomyRepository;
use App\Models\ProductTaxonomyResearchRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ProductTaxonomyReviewQueryService
{
    public function __construct(
        private readonly ProductTaxonomyRepository $taxonomy,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \App\Models\ProductTaxonomyVerification>
     */
    public function paginate(
        int $perPage,
        array $filters,
    ): LengthAwarePaginator {
        return $this->taxonomy->paginateVerifications($perPage, $filters);
    }

    /**
     * @return array{
     *     total: int,
     *     proposed: int,
     *     verified: int,
     *     overridden: int,
     *     low_confidence: int
     * }
     */
    public function summary(): array
    {
        return $this->taxonomy->verificationSummary();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function filterOptions(): array
    {
        return $this->taxonomy->verificationFilterOptions();
    }

    public function researchRun(string $uuid): ProductTaxonomyResearchRun
    {
        return $this->taxonomy->findRunByUuid($uuid);
    }
}
