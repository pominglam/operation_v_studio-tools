<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PlamodPreorder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlamodPreorder */
final class PlamodPreorderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $priceStock = $this->price_stock !== null ? (float) $this->price_stock : null;
        $unitSelling = $priceStock !== null ? round($priceStock * 1.5, 2) : null;
        $isNew = (bool) ($this->resource->getAttribute('_is_new') ?? false);

        return [
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'product_name' => $this->product_name,
            'series' => $this->series,
            'release_date' => $this->release_date?->toDateString(),
            'manufacturer' => $this->manufacturer,
            'category' => $this->category,
            'price_stock' => $this->price_stock,
            'price_preorder' => $this->price_preorder,
            'price_backorder' => $this->price_backorder,
            'unit_selling_price' => $unitSelling !== null ? number_format($unitSelling, 2, '.', '') : null,
            'quantity_preorder' => $this->quantity_preorder,
            'po_due_date' => $this->po_due_date?->toDateString(),
            'eta_date' => $this->eta_date?->toDateString(),
            'is_new' => $isNew,
            'image_url' => $this->image_storage_path !== null
                ? '/api/v1/preorders/'.rawurlencode((string) $this->sku).'/image'
                : null,
            'image_download_status' => $this->image_download_status,
            'plamod_pdp_url' => 'https://plamod.com/retailer/products/'.rawurlencode((string) $this->sku),
        ];
    }
}
