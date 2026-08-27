<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductTaxonomyRepository;
use App\DTOs\Products\ProductTaxonomyBulkApproveResultDTO;
use App\Models\ProductTaxonomyVerification;
use App\Support\Products\AgentTestSkuGuard;
use App\Support\Products\ProductTaxonomyFields;
use Throwable;

final class ProductTaxonomyBulkApprovalService
{
    public function __construct(
        private readonly ProductTaxonomyRepository $taxonomy,
        private readonly ProductTaxonomyApprovalService $approval,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function approveByFilters(
        array $filters,
        string $actor,
        bool $excludeTestSkus,
        bool $requireKitManufacturer,
        bool $dryRun = false,
        ?string $notes = null,
    ): ProductTaxonomyBulkApproveResultDTO {
        $approved = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->taxonomy->listVerifications($filters) as $verification) {
            if ($this->skipReason($verification, $excludeTestSkus, $requireKitManufacturer) !== null) {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $approved++;

                continue;
            }

            if ($this->tryApprove($verification, $actor, $notes)) {
                $approved++;
            } else {
                $failed++;
            }
        }

        return new ProductTaxonomyBulkApproveResultDTO($approved, $skipped, $failed);
    }

    public function approveEligible(
        string $actor,
        bool $excludeTestSkus,
        bool $requireKitManufacturer,
        bool $dryRun = false,
        ?string $notes = null,
    ): ProductTaxonomyBulkApproveResultDTO {
        return $this->approveByFilters(
            ['status' => 'proposed', 'archived' => 'all'],
            $actor,
            $excludeTestSkus,
            $requireKitManufacturer,
            $dryRun,
            $notes,
        );
    }

    public function tryApprove(
        ProductTaxonomyVerification $verification,
        string $actor,
        ?string $notes = null,
    ): bool {
        if ($this->skipReason($verification, true, true) !== null) {
            return false;
        }

        try {
            $this->approval->approve($verification->uuid, [], $actor, $notes);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function skipReason(
        ProductTaxonomyVerification $verification,
        bool $excludeTestSkus,
        bool $requireKitManufacturer,
    ): ?string {
        if ($verification->status !== 'proposed') {
            return 'not proposed';
        }

        $values = ProductTaxonomyFields::normalize($verification->proposed_values_json);
        if ((int) $verification->overall_confidence < $this->minimumConfidenceThreshold($values)) {
            return 'below confidence';
        }

        $product = $verification->product;
        if ($excludeTestSkus && AgentTestSkuGuard::isAgentTestSku((string) $product->sku, (string) $product->description)) {
            return 'test sku';
        }

        if ($requireKitManufacturer
            && ($values['department'] ?? null) === 'model kits'
            && ($values['manufacturer'] ?? null) === null
        ) {
            return 'kit missing manufacturer';
        }

        if (! $this->workshopIdentityComplete($values)) {
            return 'workshop missing identity';
        }

        if (! $this->accessoryIdentityComplete($values)) {
            return 'accessory missing identity';
        }

        if (! $this->figureIdentityComplete($values)) {
            return 'figure missing identity';
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function figureIdentityComplete(array $values): bool
    {
        if (($values['department'] ?? null) !== 'figures') {
            return true;
        }

        return ($values['manufacturer'] ?? null) !== null
            || ($values['product_line'] ?? null) !== null;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function accessoryIdentityComplete(array $values): bool
    {
        if (($values['department'] ?? null) !== 'accessories') {
            return true;
        }

        if (($values['accessory_kind'] ?? null) === null) {
            return false;
        }

        return ($values['manufacturer'] ?? null) !== null
            || ($values['product_line'] ?? null) !== null;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function minimumConfidenceThreshold(array $values): int
    {
        return match ($values['department'] ?? null) {
            'model kits' => 90,
            'paints', 'supplies', 'tools', 'accessories', 'figures' => 88,
            'misc' => ($values['product_line'] ?? null) === 'Keychains' ? 85 : 90,
            default => 90,
        };
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function workshopIdentityComplete(array $values): bool
    {
        if (! in_array($values['department'] ?? null, ['paints', 'supplies', 'tools'], true)) {
            return true;
        }

        return ($values['manufacturer'] ?? null) !== null
            || ($values['product_line'] ?? null) !== null
            || ($values['workshop_shelf'] ?? null) !== null;
    }
}
