<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<Product> */
final class ProductResource extends JsonResource
{
    private function money2(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
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
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->uuid,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'handle' => $product->handle,
            'type' => $product->type,
            'vendor' => $product->vendor,
            'published_on_shopify' => (bool) $product->published_on_shopify,
            'latest_unit_cost' => $this->money2($product->latest_unit_cost),
            'latest_landed_unit_cost' => $this->money2($product->latest_landed_unit_cost),
            'selling_price' => $this->money2($product->sellingPrice?->selling_price),
            'pdp' => [
                'has_description' => $this->hasExternalDescription($product),
                'plamod_image_count' => (int) ($product->plamod_image_assets_count ?? 0),
            ],
            'order' => $product->order_qty,
            'filled' => $product->filled_qty,
            'available' => $product->available_qty,
            'extended' => $this->money2($product->extended),
            'created_at' => optional($product->created_at)->toISOString(),
            'updated_at' => optional($product->updated_at)->toISOString(),
        ];
    }

    private function hasExternalDescription(Product $product): bool
    {
        $hlj = $product->hljExternalContent?->description_html;
        if (is_string($hlj) && trim($hlj) !== '') return true;

        $plamod = $product->plamodExternalContent?->description_html;
        return is_string($plamod) && trim($plamod) !== '';
    }
}
