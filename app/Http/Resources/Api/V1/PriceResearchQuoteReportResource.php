<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PriceResearchQuoteReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @extends JsonResource<PriceResearchQuoteReport>
 */
final class PriceResearchQuoteReportResource extends JsonResource
{
    private function money2OrOriginal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        $clean = preg_replace('/[^0-9\.\-]/', '', $trimmed) ?? '';
        if ($clean === '' || ! is_numeric($clean)) {
            return $trimmed;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PriceResearchQuoteReport $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'created_at' => optional($r->created_at)->toISOString(),
            'handled_at' => optional($r->handled_at)->toISOString(),
            'run_id' => $r->run_uuid,

            'product_id' => $r->product_uuid,
            'sku' => $r->sku,
            'description' => $r->product?->description,

            'site_key' => $r->site_key,
            'site_name' => $r->site_name,

            'status' => $r->status,
            'availability' => $r->availability,
            'currency' => $r->currency,
            'price' => $this->money2OrOriginal($r->price),
            'original_price' => $this->money2OrOriginal($r->original_price),
            'product_url' => $r->product_url,
            'error_message' => $r->error_message,
            'fetched_at' => optional($r->fetched_at)->toISOString(),

            'note' => $r->note,
        ];
    }
}
