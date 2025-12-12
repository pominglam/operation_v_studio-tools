<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<Product> */
final class ProductResource extends JsonResource
{
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
            'type' => $product->type,
            'price' => $product->price,
            'order' => $product->order_qty,
            'filled' => $product->filled_qty,
            'extended' => $product->extended,
            'created_at' => optional($product->created_at)->toISOString(),
            'updated_at' => optional($product->updated_at)->toISOString(),
        ];
    }
}


