<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\StorePreorders\StorePreorderListingPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StorePreorderListingPreview */
final class StorePreorderListingPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StorePreorderListingPreview $preview */
        $preview = $this->resource;

        return [
            'host' => $preview->host,
            'crawler' => $preview->crawler,
            'title' => $preview->title,
            'suggested_sku' => $preview->suggestedSku,
            'description_html' => $preview->descriptionHtml,
            'eta_date' => $preview->etaDate,
            'retail_price_cad' => $preview->retailPriceCad,
            'retail_price_note' => $preview->retailPriceNote,
            'images' => $preview->images,
            'source_url' => $preview->sourceUrl,
        ];
    }
}
