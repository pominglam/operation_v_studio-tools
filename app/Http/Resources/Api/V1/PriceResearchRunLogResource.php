<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PriceResearchRunLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @extends JsonResource<PriceResearchRunLog>
 */
final class PriceResearchRunLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PriceResearchRunLog $log */
        $log = $this->resource;

        return [
            'id' => $log->id,
            'run_id' => $log->run_uuid,
            'product_id' => $log->product_uuid,
            'sku' => $log->sku,
            'description' => $log->product?->description,
            'site_key' => $log->site_key,
            'site_name' => $log->site_name,
            'status' => $log->status,
            'product_url' => $log->product_url,
            'error_message' => $log->error_message,
            'started_at' => optional($log->started_at)->toISOString(),
            'finished_at' => optional($log->finished_at)->toISOString(),
            'duration_ms' => $log->duration_ms,
            'created_at' => optional($log->created_at)->toISOString(),
        ];
    }
}
