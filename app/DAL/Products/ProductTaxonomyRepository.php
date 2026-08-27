<?php

declare(strict_types=1);

namespace App\DAL\Products;

use App\DTOs\Products\ProductTaxonomyProposalDTO;
use App\Models\Product;
use App\Models\ProductTaxonomyResearchRun;
use App\Models\ProductTaxonomyVerification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ProductTaxonomyRepository
{
    public function createRun(string $researchVersion): ProductTaxonomyResearchRun;

    public function createQueuedRun(string $researchVersion): ProductTaxonomyResearchRun;

    public function findRunByUuid(string $uuid): ProductTaxonomyResearchRun;

    public function findRunByUuidForUpdate(string $uuid): ProductTaxonomyResearchRun;

    public function saveRun(ProductTaxonomyResearchRun $run): ProductTaxonomyResearchRun;

    public function createVerification(
        Product $product,
        ProductTaxonomyResearchRun $run,
        ProductTaxonomyProposalDTO $proposal,
        string $researchMethod,
    ): ProductTaxonomyVerification;

    public function findVerificationByUuidForUpdate(string $uuid): ProductTaxonomyVerification;

    public function saveVerification(ProductTaxonomyVerification $verification): ProductTaxonomyVerification;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ProductTaxonomyVerification>
     */
    public function paginateVerifications(
        int $perPage,
        array $filters,
    ): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ProductTaxonomyVerification>
     */
    public function listVerifications(array $filters): Collection;

    /**
     * @param  array<int, string>  $uuids
     * @return Collection<int, ProductTaxonomyVerification>
     */
    public function findVerificationsByUuids(array $uuids): Collection;

    /**
     * @return array{
     *     total: int,
     *     proposed: int,
     *     verified: int,
     *     overridden: int,
     *     low_confidence: int
     * }
     */
    public function verificationSummary(): array;

    /**
     * @return array<string, array<int, string>>
     */
    public function verificationFilterOptions(): array;
}
