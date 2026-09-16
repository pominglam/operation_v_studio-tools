<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\StorePreorder;
use App\Services\StorePreorders\StorePreorderEtaLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StorePreorder */
final class StorePreorderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $sku = $product?->sku ?? $this->plamod_sku;

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'plamod_sku' => $this->plamod_sku,
            'product_id' => $product?->uuid,
            'sku' => $sku,
            'product_name' => $product?->description,
            'deposit_percent' => $this->deposit_percent,
            'cap_qty' => $this->cap_qty,
            'remaining_qty' => $this->remainingCapQty(),
            'selling_price_cad' => $this->selling_price_cad,
            'po_cost_cad' => $this->po_cost_cad,
            'deposit_amount_cad' => $this->depositAmountCad(),
            'window_ends_on' => $this->window_ends_on?->toDateString(),
            'eta_date' => $this->etaDate(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'order_count' => (int) ($this->resource->getAttribute('order_count') ?? 0),
            'unit_qty' => (int) ($this->resource->getAttribute('unit_qty') ?? 0),
            'image_url' => $this->imageUrl(),
        ];
    }

    private function imageUrl(): string
    {
        $product = $this->product;
        $assetId = $product?->shopifyImageAssets?->first()?->id;
        if (is_int($assetId) && $assetId > 0) {
            return '/api/v1/product-assets/'.$assetId.'/thumb';
        }

        return '/api/v1/preorders/'.rawurlencode((string) $this->plamod_sku).'/image';
    }

    private function etaDate(): ?string
    {
        $stored = $this->eta_date;
        if ($stored instanceof \DateTimeInterface) {
            return $stored->format('Y-m-d');
        }
        if (is_string($stored) && $stored !== '') {
            return substr($stored, 0, 10);
        }

        return app(StorePreorderEtaLookup::class)->forSku((string) $this->plamod_sku);
    }

    private function depositAmountCad(): ?string
    {
        if ($this->selling_price_cad === null || trim((string) $this->selling_price_cad) === '') {
            return null;
        }

        $selling = (float) $this->selling_price_cad;
        $percent = (float) $this->deposit_percent;
        if ($selling <= 0 || $percent <= 0) {
            return null;
        }

        return number_format(round($selling * ($percent / 100), 2), 2, '.', '');
    }
}
