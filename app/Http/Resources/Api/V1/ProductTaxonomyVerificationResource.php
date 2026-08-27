<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ProductTaxonomyVerification;
use App\Support\Products\ProductTaxonomyFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<ProductTaxonomyVerification> */
final class ProductTaxonomyVerificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $verification = $this->resource;
        $product = $verification->product;

        return [
            'id' => $verification->uuid,
            'status' => $verification->status,
            'research_version' => $verification->research_version,
            'overall_confidence' => $verification->overall_confidence,
            'research_method' => $verification->research_method,
            'proposed_values' => $verification->proposed_values_json,
            'previous_values' => $verification->previous_values_json,
            'evidence' => $verification->evidence_json,
            'operator_notes' => $verification->operator_notes,
            'verified_by' => $verification->verified_by,
            'researched_at' => $verification->researched_at->toISOString(),
            'verified_at' => $verification->verified_at?->toISOString(),
            'overridden_at' => $verification->overridden_at?->toISOString(),
            'product' => [
                'id' => $product->uuid,
                'sku' => $product->sku,
                'description' => $product->description,
                'archived' => $product->archived_at !== null,
                'published_on_shopify' => (bool) $product->published_on_shopify,
                ...ProductTaxonomyFields::fromProduct($product),
            ],
        ];
    }
}
