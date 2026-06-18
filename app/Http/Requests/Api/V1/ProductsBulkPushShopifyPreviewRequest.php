<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ProductsBulkPushShopifyPreviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['string', 'uuid'],
            'push_options' => ['required', 'array'],
            'push_options.info' => ['sometimes', 'boolean'],
            'push_options.images' => ['sometimes', 'boolean'],
            'push_options.quantities' => ['sometimes', 'boolean'],
            'push_options.price' => ['sometimes', 'boolean'],
            'push_options.publish_status' => ['sometimes', 'boolean'],
            'push_options.sales_channels' => ['sometimes', 'boolean'],
        ];
    }
}
