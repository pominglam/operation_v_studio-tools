<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ProductTaxonomyResearchRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<ProductTaxonomyResearchRun> */
final class ProductTaxonomyResearchRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            'id' => $run->uuid,
            'status' => $run->status,
            'research_version' => $run->research_version,
            'checkpoint' => $run->checkpoint_json,
            'counts' => $run->counts_json ?? [
                'processed' => 0,
                'proposed' => 0,
                'failed' => 0,
            ],
            'error_summary' => $run->error_summary,
            'started_at' => $run->started_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'created_at' => $run->created_at?->toISOString(),
        ];
    }
}
