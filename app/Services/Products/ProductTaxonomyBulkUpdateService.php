<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductTaxonomyRepository;
use App\DTOs\Products\ProductTaxonomyBulkApproveResultDTO;
use App\Models\ProductTaxonomyVerification;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ProductTaxonomyFields;
use Throwable;

final class ProductTaxonomyBulkUpdateService
{
    public function __construct(
        private readonly ProductTaxonomyRepository $taxonomy,
        private readonly ProductTaxonomyApprovalService $approval,
    ) {}

    /**
     * @param  array<int, string>  $verificationUuids
     * @param  array<string, string|null>  $values
     */
    public function updateSelected(
        array $verificationUuids,
        array $values,
        string $actor,
        ?string $notes = null,
    ): ProductTaxonomyBulkApproveResultDTO {
        $overrides = $this->providedValues($values);
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->taxonomy->findVerificationsByUuids($verificationUuids) as $verification) {
            if ($this->skipReason($verification, $overrides) !== null) {
                $skipped++;

                continue;
            }

            try {
                $this->approval->approve($verification->uuid, $overrides, $actor, $notes);
                $updated++;
            } catch (Throwable) {
                $failed++;
            }
        }

        $missing = count($verificationUuids) - $updated - $skipped - $failed;

        return new ProductTaxonomyBulkApproveResultDTO(
            $updated,
            $skipped + max(0, $missing),
            $failed,
        );
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    private function providedValues(array $values): array
    {
        $provided = [];
        foreach (ProductTaxonomyFields::ALL as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            if ($field === 'workshop_facets') {
                $provided[$field] = ProductTaxonomyFields::normalizeFacets($values[$field]);

                continue;
            }

            $value = $values[$field];
            $provided[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return $provided;
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function skipReason(ProductTaxonomyVerification $verification, array $overrides): ?string
    {
        if ($overrides === []) {
            return 'no values';
        }
        if ($verification->status !== 'proposed') {
            return 'not proposed';
        }

        $product = $verification->product;
        $sku = strtoupper(trim((string) $product->sku));
        $description = (string) $product->description;
        if (AgentTestSkuGuard::isAgentTestSku($sku, $description)) {
            return 'test sku';
        }

        return null;
    }
}
