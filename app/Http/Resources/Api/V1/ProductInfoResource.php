<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Products\ProductInfoData;
use App\Models\ProductExternalAsset;
use App\Models\ProductExternalContent;
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
                static function (ProductExternalAsset $a): array {
                    return [
                        'id' => (int) $a->id,
                        'source' => (string) $a->source,
                        'kind' => (string) $a->kind,
                        'filename' => (string) $a->filename,
                        'mime_type' => $a->mime_type,
                        'size_bytes' => $a->size_bytes,
                        'origin_url' => $a->origin_url,
                        'origin_width' => $a->origin_width,
                        'origin_height' => $a->origin_height,
                        'checksum_sha256' => $a->checksum_sha256,
                        'download_url' => '/api/v1/product-assets/'.$a->id.'/download',
                        'view_url' => '/api/v1/product-assets/'.$a->id.'/view',
                    ];
                },
                $data->assets,
            ),
        ];
    }
}

