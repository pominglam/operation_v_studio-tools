<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\Products\PlamodProductData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @extends JsonResource<PlamodProductData>
 */
final class PlamodProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlamodProductData $data */
        $data = $this->resource;

        return [
            'source' => 'plamod',
            'content' => $data->content ? [
                'source' => $data->content->source,
                'source_url' => $data->content->source_url,
                'title' => $data->content->title,
                'description_html' => $data->content->description_html,
                'attributes' => $data->content->attributes_json,
                'updated_at' => $data->content->updated_at?->toIso8601String(),
            ] : null,
            'assets' => array_map(static function ($a): array {
                return [
                    'id' => (int) $a->id,
                    'kind' => (string) $a->kind,
                    'filename' => (string) $a->filename,
                    'mime_type' => $a->mime_type,
                    'size_bytes' => $a->size_bytes,
                    'download_url' => '/api/v1/product-assets/'.$a->id.'/download',
                    'view_url' => '/api/v1/product-assets/'.$a->id.'/view',
                ];
            }, $data->assets),
        ];
    }
}
