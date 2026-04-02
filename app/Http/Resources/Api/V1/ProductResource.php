<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<Product> */
final class ProductResource extends JsonResource
{
    private function money2(string|int|float|null $value): ?string
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
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->uuid,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'description' => $product->description,
            'handle' => $product->handle,
            'main_type' => $product->main_type,
            'type' => $product->type,
            'grade' => $product->grade,
            'series' => $product->series,
            'scale' => $product->scale,
            'yen_price' => $product->yen_price,
            'bandai_launch_date' => optional($product->bandai_launch_date)->toDateString(),
            'vendor' => $product->vendor,
            'brand' => $product->brand,
            'published_on_shopify' => (bool) $product->published_on_shopify,
            'archived_at' => optional($product->archived_at)->toISOString(),
            'is_archived' => $product->archived_at !== null,
            'is_ready' => (bool) $product->is_ready,
            'latest_arrival' => (bool) $product->latest_arrival,
            'latest_unit_cost' => $this->money2($product->latest_unit_cost),
            'latest_landed_unit_cost' => $this->money2($product->latest_landed_unit_cost),
            'selling_price' => $this->money2($product->sellingPrice?->selling_price),
            'pdp' => [
                'has_description' => $this->hasExternalDescription($product),
                'plamod_image_count' => (int) ($product->plamod_image_assets_count ?? 0),
            ],
            'order' => $product->order_qty,
            'filled' => $product->filled_qty,
            'total_ordered' => max(0, (int) ($product->getAttribute('total_ordered_qty') ?? 0)),
            'available' => $product->available_qty,
            'maintain' => $product->maintain_qty,
            'not_arrived' => max(0, (int) ($product->getAttribute('inbound_open_po_qty') ?? 0)),
            'reorder' => max(0, (int) ($product->getAttribute('reorder_qty') ?? 0)),
            'extended' => $this->money2($product->extended),
            'po_total_cost' => $this->money2($product->getAttribute('po_total_cost')),
            'created_at' => optional($product->created_at)->toISOString(),
            'updated_at' => optional($product->updated_at)->toISOString(),
        ];
    }

    private function hasExternalDescription(Product $product): bool
    {
        $computed = $product->getAttribute('pdp_has_description');
        if ($computed !== null) {
            return (bool) $computed;
        }

        $hlj = $product->hljExternalContent?->description_html;
        if (is_string($hlj) && trim($hlj) !== '') {
            return true;
        }

        $plamod = $product->plamodExternalContent?->description_html;
        if (is_string($plamod) && trim($plamod) !== '') {
            return true;
        }

        // If other sources were eager-loaded, consider them as well (avoid extra DB queries).
        $contents = $product->externalContents?->all() ?? [];
        foreach ($contents as $c) {
            if (! $c instanceof \App\Models\ProductExternalContent) {
                continue;
            }
            if (! is_string($c->description_html) || trim($c->description_html) === '') {
                continue;
            }

            return true;
        }

        return false;
    }
}
