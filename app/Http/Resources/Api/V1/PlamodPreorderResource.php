<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PlamodPreorder;
use App\Support\Pricing\CharmPricingCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlamodPreorder */
final class PlamodPreorderResource extends JsonResource
{
    private const string UNIT_COST_MULTIPLIER = '1.5';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unitSelling = CharmPricingCalculator::sellingPriceX99FromCost(
            $this->preorderCostBasis(),
            self::UNIT_COST_MULTIPLIER,
        );
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
            'unit_selling_price' => $unitSelling,
            'quantity_preorder' => $this->quantity_preorder,
            'po_due_date' => $this->po_due_date?->toDateString(),
            'eta_date' => $this->eta_date?->toDateString(),
            'is_new' => $isNew,
            'not_in_import' => (bool) ($this->resource->getAttribute('_not_in_import') ?? false),
            'image_url' => $this->image_storage_path !== null
                ? '/api/v1/preorders/'.rawurlencode((string) $this->sku).'/image'
                : ($this->source_image_url !== null && trim((string) $this->source_image_url) !== ''
                    ? trim((string) $this->source_image_url)
                    : null),
            'image_download_status' => $this->image_download_status,
            'plamod_pdp_url' => 'https://plamod.com/retailer/products/'.rawurlencode((string) $this->sku),
        ];
    }

    private function preorderCostBasis(): ?string
    {
        if ($this->price_preorder !== null && trim((string) $this->price_preorder) !== '') {
            return trim((string) $this->price_preorder);
        }

        if ($this->price_stock !== null && trim((string) $this->price_stock) !== '') {
            return trim((string) $this->price_stock);
        }

        return null;
    }
}
