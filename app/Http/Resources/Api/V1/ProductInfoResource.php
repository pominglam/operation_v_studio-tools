<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Products\ProductInfoData;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
use App\Support\Products\ProductExternalAssetApiArray;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @extends JsonResource<ProductInfoData>
 */
final class ProductInfoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductInfoData $data */
        $data = $this->resource;

        return [
            'preferred_description_source' => $data->preferredDescriptionSource,
            'contents' => array_map(
                static function (ProductExternalContent $c): array {
                    return [
                        'source' => (string) $c->source,
                        'source_url' => $c->source_url,
                        'title' => $c->title,
                        'description_html' => $c->description_html,
                        'attributes' => $c->attributes_json,
                        'updated_at' => $c->updated_at?->toIso8601String(),
                    ];
                },
                $data->contents,
            ),
            'assets' => array_map(
                static fn (ProductExternalAsset $a): array => ProductExternalAssetApiArray::from($a),
                $data->assets,
            ),
        ];
    }
}
