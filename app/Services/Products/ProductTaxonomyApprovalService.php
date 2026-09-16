<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\DAL\Products\ProductRepository;
use App\DAL\Products\ProductTaxonomyRepository;
use App\Models\ProductTaxonomyVerification;
use App\Services\Products\Exceptions\ProductTaxonomyVerificationStateException;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Support\Facades\DB;

final class ProductTaxonomyApprovalService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTaxonomyRepository $taxonomy,
    ) {}

    /**
     * @param  array<string, string|null>  $overrides
     */
    public function approve(
        string $verificationUuid,
        array $overrides,
        string $actor,
        ?string $notes = null,
    ): ProductTaxonomyVerification {
        return DB::transaction(function () use ($verificationUuid, $overrides, $actor, $notes) {
            $verification = $this->taxonomy->findVerificationByUuidForUpdate($verificationUuid);

            return match ($verification->status) {
                'proposed' => $this->applyProposed($verification, $overrides, $actor, $notes),
                'verified', 'overridden' => $this->applyCorrection($verification, $overrides, $actor, $notes),
                default => throw new ProductTaxonomyVerificationStateException(
                    'This taxonomy verification record cannot be applied.',
                ),
            };
        });
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function applyProposed(
        ProductTaxonomyVerification $verification,
        array $overrides,
        string $actor,
        ?string $notes,
    ): ProductTaxonomyVerification {
        $proposed = ProductTaxonomyFields::normalize($verification->proposed_values_json);
        $applied = ProductTaxonomyFields::normalize([...$proposed, ...$overrides]);

        return $this->persistAppliedValues($verification, $applied, $proposed, $actor, $notes);
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function applyCorrection(
        ProductTaxonomyVerification $verification,
        array $overrides,
        string $actor,
        ?string $notes,
    ): ProductTaxonomyVerification {
        if ($overrides === []) {
            throw new ProductTaxonomyVerificationStateException(
                'Provide at least one field value to update a verified taxonomy record.',
            );
        }

        $product = $verification->product;
        $current = ProductTaxonomyFields::fromProduct($product);
        $applied = ProductTaxonomyFields::normalize([...$current, ...$overrides]);
        $proposed = ProductTaxonomyFields::normalize($verification->proposed_values_json);

        return $this->persistAppliedValues($verification, $applied, $proposed, $actor, $notes);
    }

    /**
     * @param  array<string, string|null>  $applied
     * @param  array<string, string|null>  $proposed
     */
    private function persistAppliedValues(
        ProductTaxonomyVerification $verification,
        array $applied,
        array $proposed,
        string $actor,
        ?string $notes,
    ): ProductTaxonomyVerification {
        $isOverride = ProductTaxonomyFields::valuesDiffer($proposed, $applied);

        $product = $verification->product;
        $product->fill($applied);
        $this->products->save($product);

        $verification->status = $isOverride ? 'overridden' : 'verified';
        $verification->verified_at = now();
        $verification->overridden_at = $isOverride ? now() : null;
        $verification->verified_by = trim($actor);
        if ($notes !== null) {
            $verification->operator_notes = trim($notes);
        }

        return $this->taxonomy->saveVerification($verification);
    }
}
