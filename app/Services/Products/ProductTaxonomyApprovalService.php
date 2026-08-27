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
            if ($verification->status !== 'proposed') {
                throw new ProductTaxonomyVerificationStateException(
                    'Only proposed taxonomy verification records can be applied.',
                );
            }

            $proposed = ProductTaxonomyFields::normalize($verification->proposed_values_json);
            $applied = ProductTaxonomyFields::normalize([...$proposed, ...$overrides]);
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
        });
    }
}
